<?php

namespace UniverseAdventure;

use UniverseAdventure\generator\PlanetGenerator;
use UniverseAdventure\generator\UniverseGenerator;
use UniverseAdventure\entity\SpaceCarrier;
use UniverseAdventure\world\EarthAsset;
use UniverseAdventure\task\UniverseTickTask;
use UniverseAdventure\world\PlanetRegistry;
use UniverseAdventure\world\UniverseLayout;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\block\Block;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\level\LevelLoadEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\normal\Normal;
use pocketmine\level\particle\FlameParticle;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\protocol\PlayerInputPacket;
use pocketmine\network\protocol\MovePlayerPacket;
use pocketmine\network\protocol\PlayStatusPacket;
use pocketmine\network\protocol\FullChunkDataPacket;
use pocketmine\network\protocol\InteractPacket;
use pocketmine\network\protocol\SetTimePacket;
use pocketmine\network\protocol\ChangeDimensionPacket;
use pocketmine\network\protocol\ChunkRadiusUpdatePacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener{
    // Nether relay is a protocol fixture, not a tuning value.
    // Keeping the relay at one chunk-local centre prevents future releases or
    // stale config files from moving the player to a different Nether cell.
    const NETHER_RELAY_X = 520;
    const NETHER_RELAY_Y = 112;
    const NETHER_RELAY_Z = 520;
    private $registry;
    private $universeName = "universe";
    private $planetPrefix = "planet";
    private $archivePath;
    private $layout = [];
    private $flights = [];
    private $cooldowns = [];
    private $customPlayers = [];
    private $originalFlight = [];
    private $originalNightVision = [];
    private $unloadAt = [];
    private $universeReady = false;
    private $earthWorldReady = false;
    private $universeChunksQueued = false;
    private $planetWeatherPhase = [];
    private $inputDebug = [];
    private $inputDebugAt = [];
    private $planetGravityWorld = [];
    private $originalGravityEffects = [];
    private $flightPostureRestores = [];
    private $flightTransitions = [];
    private $worldResyncs = [];
    private $chunkRefreshes = [];
    private $dimensionTransitions = [];
    private $dimensionTransitionStore;
    private $planetLandingGuards = [];
    private $usedChunksProperty = null;
    private $usedChunksReflectionFailed = false;
    private $emptyChunkPayload = null;
    private $spaceCarriers = [];
    private $spaceFlightAssist = [];
    private $inAirTicksProperty = null;
    private $inAirTicksReflectionFailed = false;
    private $inAirTicksReflectionWarned = false;
    private $earthChunkStreams = [];
    private $universeEarthShellDone = false;
    private $universeLightingRepairDone = false;
    private $universeMigrationWarned = false;
    private $legacyCarrierPurgeDone = false;
    private $woolChunkCompactions = [];
    private $woolTreeBackfills = [];
    private $virtualRelayPacketBypass = [];
    private $clientArrivalFinalizers = [];
    private $recentGroundFlightResets = [];

    public function onLoad(){
        $this->saveDefaultConfig();
        $this->reloadRuntimeConfig();
        @mkdir($this->getDataFolder(), 0777, true);
        @mkdir($this->archivePath, 0777, true);
        if(is_file($this->getFile() . "resources" . DIRECTORY_SEPARATOR . "earth.bin.gz") && !is_file($this->getDataFolder() . "earth.bin.gz")){
            $this->saveResource("earth.bin.gz", false);
        }
        Generator::addGenerator(UniverseGenerator::class, "universe");
        Generator::addGenerator(PlanetGenerator::class, "randomplanet");
        Entity::registerEntity(SpaceCarrier::class, true);
        $this->registerCreativeAircraft();
        $this->archiveStrandedPlanetWorlds();
    }

    public function onEnable(){
        $this->dimensionTransitionStore = new Config($this->getDataFolder() . "pending-transitions.yml", Config::YAML, []);
        $this->registry = new PlanetRegistry(
            $this->getDataFolder() . "planets.yml",
            $this->planetPrefix,
            (int) $this->getConfig()->getNested("planet-worlds.surface-depth", 3),
            (array) $this->getConfig()->getNested("planet-worlds.climate", []),
            (int) $this->getConfig()->getNested("planet-worlds.wool-shell-depth", 3)
        );
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        foreach($this->getServer()->getLevels() as $level){
            $this->stabilizeBrokenWeather($level);
        }
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new UniverseTickTask($this), 1);
        $this->getLogger()->info("宇宙系统已启用；将在服务器启动完成后准备 universe 世界。");
    }

    public function onDisable(){
        // Never "finish" a two-hop transfer while the server is shutting down.
        // A direct Nether -> target teleport here used to bypass the very cache
        // flush state machine we rely on.  If the player is already in Nether,
        // keep the persisted target so onJoin() can safely resume it; otherwise
        // cancel the stale request and let the player retry after restart.
        $netherName = strtolower((string) $this->getConfig()->getNested("dimension-transfer.nether-world", "nether"));
        foreach($this->dimensionTransitions as $name => $transition){
            $player = $this->getServer()->getPlayerExact($name);
            if($player instanceof Player){
                $this->restoreTransitionGamemodeIfNeeded($player, $name);
            }
            if(!($player instanceof Player) || strtolower($player->getLevel()->getFolderName()) !== $netherName){
                if($this->dimensionTransitionStore instanceof Config){
                    $this->dimensionTransitionStore->remove($name);
                }
            }
        }
        if($this->dimensionTransitionStore instanceof Config){ $this->dimensionTransitionStore->save(); }
        $this->dimensionTransitions = [];
        foreach(array_keys($this->flights) as $name){
            $player = $this->getServer()->getPlayerExact($name);
            $this->endFlight($name, $player, true);
        }
        foreach($this->getServer()->getOnlinePlayers() as $player){
            // During shutdown there is no scheduler time left to complete a
            // Nether two-hop. Do not start a fresh cross-world teleport here.
            // Just remove temporary abilities/effects; the player's current
            // world is saved normally and startup recovery handles old planets.
            $this->leaveCustomRules($player);
        }
        foreach($this->flightPostureRestores as $name => $restore){
            $player = $this->getServer()->getPlayerExact($name);
            if($player instanceof Player){
                $player->setAllowFlight($player->isCreative() || (bool) $restore["allow"]);
            }
        }
        $this->flightPostureRestores = [];
        foreach($this->getLoadedPlanetNames() as $world){
            // Never force-unload a planet containing a player during shutdown.
            // Empty planets can still be archived safely.
            $this->unloadAndArchive($world, false);
        }
        if($this->registry instanceof PlanetRegistry){
            $this->registry->save();
        }
    }

    private function reloadRuntimeConfig(){
        $this->reloadConfig();
        // saveDefaultConfig() does not merge new keys into an existing config.
        // 1.0.5 keeps the proven rendering/planet fixes, but retires the virtual
        // Earth relay by default. Both Earth and planets now use the same real
        // Nether 3x3 cache-flush path, which is far less likely to leave the old
        // client staring at an all-air synthetic dimension after rapid re-entry.
        $configChanged = false;
        $space = (array) $this->getConfig()->get("space", []);
        foreach([
            "speed-effect-level",
            "flight-boost-max-source-step",
            "flight-boost-motion",
            "movement-multiplier",
            "movement-max-input-step",
            "carrier-enabled",
            "carrier-horizontal-speed",
            "carrier-vertical-speed",
            "carrier-acceleration",
            "carrier-deceleration",
            "carrier-input-timeout",
            "carrier-input-deadzone",
            "carrier-look-flight",
            "carrier-mount-delay-ticks",
            "carrier-link-resends",
            "carrier-link-resend-interval-ticks",
            "carrier-mount-settle-ticks",
            "flight-assist-hover-correction",
            "flight-assist-hover-correction-max",
            "flight-assist-gravity-compensation",
            "flight-assist-gravity-fade-vertical",
            "flight-assist-packet-input-timeout",
            "flight-assist-hover-gain",
            "flight-assist-hover-max",
            "flight-assist-hover-deadzone",
            "flight-assist-vertical-intent-deadzone"
        ] as $obsoleteSpaceKey){
            if(array_key_exists($obsoleteSpaceKey, $space)){
                unset($space[$obsoleteSpaceKey]);
                $configChanged = true;
            }
        }
        if($configChanged){
            $this->getConfig()->set("space", $space);
        }

        // The 0.5.7/0.5.8 Earth centre (-8192,-8192) sits exactly on both a
        // 16-block chunk seam and the four-way 512-block McRegion boundary.
        // The uploaded real world proves all 137376 Earth blocks are present
        // on disk, so 0.5.9 moves the landmark to a deliberately unaligned
        // centre. Its complete 64^3 footprint now stays inside one .mcr file.
        $legacyCenter = $this->getConfig()->getNested("coordinate-isolation.universe-earth-center", null);
        if(is_array($legacyCenter) && count($legacyCenter) >= 3
            && (int) $legacyCenter[0] === -8192 && (int) $legacyCenter[1] === 64 && (int) $legacyCenter[2] === -8192){
            $this->getConfig()->setNested("coordinate-isolation.universe-earth-center", [-7992, 64, -7992]);
            $configChanged = true;
        }
        // Mobility migrations. Only replace exact stock values so custom
        // server tuning is preserved. 1.0.4 deliberately raises braking and
        // turn response: lowering deceleration in 1.0.3 accidentally increased
        // inertia, and an old anti-self-feedback guard also rejected genuine
        // reverse input until the previous drift had almost stopped.
        $mobilityMigrations = [
            "space.flight-assist-max-speed" => [0.45, 0.55],
            "space.flight-assist-acceleration" => [0.055, 0.075],
            "space.flight-assist-deceleration" => [0.075, 0.045],
            "space.flight-assist-input-timeout" => [0.22, 0.35]
        ];
        foreach($mobilityMigrations as $key => $pair){
            $current = $this->getConfig()->getNested($key, null);
            if($current !== null && abs((float) $current - (float) $pair[0]) < 0.000001){
                $this->getConfig()->setNested($key, $pair[1]);
                $configChanged = true;
            }
        }
        // 1.0.4 stock migration from the 1.0.3 release values.
        foreach([
            "space.flight-assist-acceleration" => [0.075, 0.11],
            "space.flight-assist-deceleration" => [0.045, 0.16],
            "space.flight-assist-input-timeout" => [0.35, 0.25]
        ] as $key => $pair){
            $current = $this->getConfig()->getNested($key, null);
            if($current !== null && abs((float) $current - (float) $pair[0]) < 0.000001){
                $this->getConfig()->setNested($key, $pair[1]);
                $configChanged = true;
            }
        }

        // 1.0.5 stock tuning migration.
        foreach([
            "space.flight-assist-acceleration" => [0.11, 0.10],
            "space.flight-assist-deceleration" => [0.16, 0.13]
        ] as $key => $pair){
            $current = $this->getConfig()->getNested($key, null);
            if($current !== null && abs((float) $current - (float) $pair[0]) < 0.000001){
                $this->getConfig()->setNested($key, $pair[1]);
                $configChanged = true;
            }
        }

        // 1.0.6 returns to the softer 1.0.1 vector controller because the
        // immediate-heading controller in 1.0.5 made MCPE 0.14 camera motion
        // noticeably harsher. Only exact 1.0.5 stock values are migrated;
        // operator tuning is left untouched. Drag is deliberately much lower
        // than 1.0.1, while a separate turn acceleration keeps reversals usable.
        foreach([
            "space.flight-assist-max-speed" => [0.55, 0.58],
            "space.flight-assist-vertical-speed" => [0.72, 0.68],
            "space.flight-assist-acceleration" => [0.10, 0.065],
            "space.flight-assist-deceleration" => [0.13, 0.025],
            "space.flight-assist-vertical-acceleration" => [0.24, 0.10],
            "space.flight-assist-sneak-acceleration" => [0.40, 0.24],
            "space.flight-assist-input-timeout" => [0.25, 0.30],
            "space.flight-assist-input-threshold" => [0.018, 0.008],
            "space.flight-assist-view-pitch-deadzone" => [7.0, 5.0]
        ] as $key => $pair){
            $current = $this->getConfig()->getNested($key, null);
            if($current !== null && abs((float) $current - (float) $pair[0]) < 0.000001){
                $this->getConfig()->setNested($key, $pair[1]);
                $configChanged = true;
            }
        }

        // 1.0.9: DATA_NO_AI must never be used on Player. On this old client/core
        // combination it can freeze normal player movement. Instead we suppress
        // only Genisys' anti-fly timer by keeping Player::$inAirTicks negative
        // while the player is physically in Universe. This is server-side only
        // and sends no entity metadata to the client.
        $space = (array) $this->getConfig()->get("space", []);
        if(array_key_exists("antifly-bypass", $space)){
            unset($space["antifly-bypass"]);
            $this->getConfig()->set("space", $space);
            $configChanged = true;
        }
        if($this->getConfig()->getNested("space.antifly-counter-bypass", null) === null){
            $this->getConfig()->setNested("space.antifly-counter-bypass", true);
            $configChanged = true;
        }
        // Exact 1.0.7 stock values are safe to migrate; deliberate operator
        // tuning is left alone. 1.0.7 removed several old view-thrust keys, so
        // the default loop below recreates those when absent.
        foreach([
            "space.flight-assist-max-speed" => [0.62, 0.54],
            "space.flight-assist-vertical-speed" => [0.62, 0.54],
            "space.flight-assist-acceleration" => [0.095, 0.065],
            "space.flight-assist-deceleration" => [0.035, 0.025],
            "space.flight-assist-vertical-acceleration" => [0.14, 0.085],
            "space.flight-assist-sneak-acceleration" => [0.30, 0.24],
            "space.flight-assist-input-timeout" => [0.32, 0.26],
            "space.flight-assist-input-threshold" => [0.008, 0.012],
            "space.flight-assist-turn-acceleration" => [0.20, 0.14],
            "space.flight-assist-vertical-idle-brake" => [0.10, 0.07]
        ] as $key => $pair){
            $current = $this->getConfig()->getNested($key, null);
            if($current !== null && abs((float) $current - (float) $pair[0]) < 0.000001){
                $this->getConfig()->setNested($key, $pair[1]);
                $configChanged = true;
            }
        }

        foreach([
            "space.flight-assist-vertical-speed" => [0.46, 0.62],
            "space.flight-assist-vertical-acceleration" => [0.16, 0.14],
            "space.flight-assist-input-timeout" => [0.24, 0.32]
        ] as $key => $pair){
            $current = $this->getConfig()->getNested($key, null);
            if($current !== null && abs((float) $current - (float) $pair[0]) < 0.000001){
                $this->getConfig()->setNested($key, $pair[1]);
                $configChanged = true;
            }
        }
        foreach([
            "space.flight-assist-max-speed" => 0.54,
            "space.flight-assist-vertical-speed" => 0.54,
            "space.flight-assist-acceleration" => 0.065,
            "space.flight-assist-deceleration" => 0.025,
            "space.flight-assist-vertical-acceleration" => 0.085,
            "space.flight-assist-sneak-acceleration" => 0.24,
            "space.flight-assist-input-timeout" => 0.26,
            "space.flight-assist-input-threshold" => 0.012,
            "space.flight-assist-view-pitch-deadzone" => 6.0,
            "space.flight-assist-turn-acceleration" => 0.14,
            "space.flight-assist-vertical-idle-brake" => 0.07
        ] as $key => $value){
            if($this->getConfig()->getNested($key, null) === null){
                $this->getConfig()->setNested($key, $value);
                $configChanged = true;
            }
        }
        // Retire 1.0.7-only control keys if they are still present. They are
        // harmless, but removing them makes the generated config describe the
        // controller that is actually running.
        $space = (array) $this->getConfig()->get("space", []);
        foreach([
            "flight-assist-horizontal-speed",
            "flight-assist-horizontal-acceleration",
            "flight-assist-horizontal-deceleration",
            "flight-assist-vertical-brake",
            "flight-assist-direction-smoothing",
            "flight-assist-jump-pulse-seconds",
            "flight-assist-jump-packet-timeout"
        ] as $retiredKey){
            if(array_key_exists($retiredKey, $space)){
                unset($space[$retiredKey]);
                $configChanged = true;
            }
        }
        $this->getConfig()->set("space", $space);

        // 1.0.5: the synthetic Earth relay can accumulate stale client-only
        // chunk state during rapid Universe <-> Earth cycles. Migrate the stock
        // 1.0.1-1.0.4 default to the proven real-Nether path. The option remains
        // available for operators who explicitly turn it back on later.
        $virtualEarth = $this->getConfig()->getNested("dimension-transfer.virtual-earth-relay", null);
        if($virtualEarth === true){
            $this->getConfig()->setNested("dimension-transfer.virtual-earth-relay", false);
            $configChanged = true;
        }

        // 1.0.10: return to the MCPE client's native flight physics in Universe.
        // Every server-side Motion controller tested so far either fought Genisys anti-fly
        // or produced visible camera corrections. Native allow_fly is the only truly smooth
        // movement path on 0.14, so the half-flight bug is now solved at the real Nether
        // relay: inventory-safe gamemode pulse -> natural fall -> verified ground contact.
        foreach([
            "space.flight-assist-enabled" => false,
            "space.survival-native-flight" => true,
            "space.antifly-counter-bypass" => false,
            "dimension-transfer.virtual-earth-relay" => false
        ] as $key => $value){
            if($this->getConfig()->getNested($key, null) !== $value){
                $this->getConfig()->setNested($key, $value);
                $configChanged = true;
            }
        }
        foreach([
            "dimension-transfer.native-flight-reset" => true,
            "dimension-transfer.reset-spawn-height" => 6,
            "dimension-transfer.reset-pulse-delay-ticks" => 4,
            "dimension-transfer.nether-client-settle-ticks" => 40,
            "dimension-transfer.nether-resync-interval-ticks" => 20,
            "dimension-transfer.ground-confirm-ticks" => 6,
            "dimension-transfer.ground-timeout-ticks" => 160
        ] as $key => $value){
            if($this->getConfig()->getNested($key, null) === null){
                $this->getConfig()->setNested($key, $value);
                $configChanged = true;
            }
        }

        $defaults = [
            "coordinate-isolation.universe-earth-center" => [-7992, 64, -7992],
            "coordinate-isolation.universe-entry-offset" => [0, 0, 40],
            "coordinate-isolation.earth-world-spawn-xz" => [8200, 8200],
            "earth.shell-thickness" => 1,
            "earth.stagger-chunk-send" => true,
            "earth.chunk-send-interval-ticks" => 2,
            "space.flight-assist-enabled" => false,
            "space.survival-native-flight" => true,
            "space.antifly-counter-bypass" => false,
            "space.flight-assist-max-speed" => 0.54,
            "space.flight-assist-vertical-speed" => 0.54,
            "space.flight-assist-acceleration" => 0.065,
            "space.flight-assist-deceleration" => 0.025,
            "space.flight-assist-vertical-acceleration" => 0.085,
            "space.flight-assist-sneak-acceleration" => 0.24,
            "space.flight-assist-input-timeout" => 0.26,
            "space.flight-assist-input-threshold" => 0.012,
            "space.flight-assist-max-source-step" => 0.80,
            "space.flight-assist-send-interval-ticks" => 1,
            "space.flight-assist-view-pitch-deadzone" => 6.0,
            "space.flight-assist-turn-acceleration" => 0.14,
            "space.flight-assist-vertical-idle-brake" => 0.07,
            "dimension-transfer.virtual-earth-relay" => false,
            "dimension-transfer.virtual-hold-ticks" => 60,
            "dimension-transfer.virtual-fake-radius" => 1,
            "dimension-transfer.virtual-return-radius" => 3,
            "dimension-transfer.client-reset-after-flight-revoke" => true,
            "dimension-transfer.native-flight-reset" => true,
            "dimension-transfer.reset-spawn-height" => 6,
            "dimension-transfer.reset-pulse-delay-ticks" => 4,
            "dimension-transfer.nether-client-settle-ticks" => 40,
            "dimension-transfer.nether-resync-interval-ticks" => 20,
            "dimension-transfer.ground-confirm-ticks" => 6,
            "dimension-transfer.ground-timeout-ticks" => 160,
            "planet-worlds.wool-shell-depth" => 3
        ];
        foreach($defaults as $key => $value){
            if($this->getConfig()->getNested($key, null) === null){
                $this->getConfig()->setNested($key, $value);
                $configChanged = true;
            }
        }
        if($configChanged){
            $this->getConfig()->save();
        }
        $this->universeName = (string) $this->getConfig()->getNested("worlds.universe", "universe");
        $this->planetPrefix = preg_replace('/[^a-z0-9]/', '', strtolower((string) $this->getConfig()->getNested("planet-worlds.prefix", "planet")));
        $archiveFolder = basename((string) $this->getConfig()->getNested("planet-worlds.archive-folder", "planet_worlds"));
        $this->archivePath = $this->getDataFolder() . $archiveFolder . DIRECTORY_SEPARATOR;
        $earthCenter = $this->getUniverseEarthCenter();
        $this->layout = [
            "cellSize" => (int) $this->getConfig()->getNested("space.sphere-cell-size", 64),
            "minRadius" => (int) $this->getConfig()->getNested("space.sphere-min-radius", 4),
            "maxRadius" => (int) $this->getConfig()->getNested("space.sphere-max-radius", 12),
            "minY" => (int) $this->getConfig()->getNested("space.sphere-min-y", 20),
            "maxY" => (int) $this->getConfig()->getNested("space.sphere-max-y", 108),
            "earthExclusion" => (int) $this->getConfig()->getNested("earth.exclusion-radius", 96),
            "earthX" => (float) $earthCenter[0],
            "earthZ" => (float) $earthCenter[2],
            "blocks" => (array) $this->getConfig()->getNested("space.safe-blocks", [])
        ];
    }

    public function tickUniverse($currentTick){
        $this->tickDimensionTransitions();
        $this->tickPlanetLandingGuards();
        $this->tickClientArrivalFinalizers();
        $this->tickFlightPostureRestores();
        $this->tickFlightTransitions();
        $this->tickWorldResyncs();
        $this->tickChunkRefreshes();
        $this->tickEarthChunkStreams();
        if(!$this->universeReady){
            // World preparation may take several async population passes, but
            // players who rejoin inside Universe still need flight/night rules
            // immediately. Readiness gates entry, not the runtime rule loop.
            $this->universeReady = $this->ensureUniverseWorld();
        }
        if(!$this->earthWorldReady){
            $this->earthWorldReady = $this->ensureEarthWorld() !== null;
        }

        $this->tickFlights($currentTick);
        foreach($this->getServer()->getOnlinePlayers() as $player){
            $runtimeName = strtolower($player->getName());
            // A virtual relay intentionally leaves the authoritative player in
            // Universe for a few seconds. Do not re-apply Universe allowFlight
            // or touch sphere triggers while the client is in synthetic Nether.
            if(isset($this->dimensionTransitions[$runtimeName])
                && ($this->dimensionTransitions[$runtimeName]["relayMode"] ?? "real") === "virtual"){
                continue;
            }
            $world = $player->getLevel()->getFolderName();
            if($world === $this->universeName){
                $this->clearPlanetGravity($player);
                $this->applyUniverseRules($player, $currentTick);
            }elseif($this->isPlanetWorld($world)){
                $this->applyPlanetRules($player, $world, $currentTick);
            }elseif(isset($this->customPlayers[strtolower($player->getName())]) && !isset($this->dimensionTransitions[strtolower($player->getName())])){
                // Nether is an internal relay, not a real exit from custom
                // rules. Revoking allowFlight in the middle of a dimension hop
                // is what creates the intermittent half-flight state on Earth.
                $this->clearPlanetGravity($player);
                $this->leaveCustomRules($player);
            }
        }
        $this->tickSpaceFlightAssist($currentTick);

        if(($currentTick % 20) === 0){
            $this->tickWorldLifecycle();
        }
        // The 0.14 client can locally resume daylight after a world/chunk
        // rebuild unless it receives a fresh SetTimePacket. Universe is a
        // hard permanent-night world, so reassert it once per second while
        // players are there instead of only mutating the server-side clock.
        if(($currentTick % 20) === 0){
            $level = $this->getServer()->getLevelByName($this->universeName);
            if($level !== null && count($level->getPlayers()) > 0){
                $this->syncUniverseTime();
            }
        }
    }

    private function ensureUniverseWorld(){
        $server = $this->getServer();
        $center = $this->getUniverseEarthCenter();
        $entry = $this->getUniverseEarthEntry();
        $marker = $this->getDataFolder() . "universe-render-layout-v2.json";
        if($server->isLevelGenerated($this->universeName) && !$this->isUniverseRenderLayoutCurrent($marker, $center)){
            if(!$this->universeMigrationWarned){
                $this->universeMigrationWarned = true;
                $this->getLogger()->warning("0.5.9 已确认旧 Universe 的 Earth 方块在磁盘上完整，但旧中心正压在 chunk/McRegion 交界。请完整停服后备份并删除 worlds/" . $this->universeName . " 后重启，让 Earth 以新的单-region、非 chunk 对齐布局重建。插件不会自动删除世界。 ");
            }
            return false;
        }
        if(!$server->isLevelLoaded($this->universeName)){
            if($server->isLevelGenerated($this->universeName)){
                if(!$server->loadLevel($this->universeName)){
                    return false;
                }
            }else{
                $asset = $this->getDataFolder() . basename((string) $this->getConfig()->getNested("earth.asset", "earth.bin.gz"));
                $settings = [
                    "earthAsset" => $asset,
                    "earthCenter" => $center,
                    "earthShellThickness" => max(1, min(8, (int) $this->getConfig()->getNested("earth.shell-thickness", 1))),
                    "entry" => $entry,
                    "layout" => $this->layout,
                    "biomeId" => (int) $this->getConfig()->getNested("space.biome-id", 1),
                    "biomeColor" => (array) $this->getConfig()->getNested("space.biome-color", [24, 24, 40])
                ];
                $ok = $server->generateLevel($this->universeName, null, UniverseGenerator::class, ["preset" => json_encode($settings)]);
                if(!$ok && !$server->isLevelGenerated($this->universeName)){
                    $this->getLogger()->error("无法创建 universe 世界。");
                    return false;
                }
                if(!$server->isLevelLoaded($this->universeName)){
                    $server->loadLevel($this->universeName);
                }
                @file_put_contents($marker, json_encode(["center" => $center, "entry" => $entry, "version" => 2]));
            }
        }
        $level = $server->getLevelByName($this->universeName);
        if($level !== null){
            if(!$this->legacyCarrierPurgeDone){
                $purged = 0;
                foreach($level->getEntities() as $entity){
                    if($entity instanceof SpaceCarrier){
                        $entity->close();
                        ++$purged;
                    }
                }
                $this->legacyCarrierPurgeDone = true;
                if($purged > 0){
                    $this->getLogger()->info("已清理 " . $purged . " 个 0.6.0/0.6.1 遗留 SpaceCarrier 实体。");
                }
            }
            $level->setSpawnLocation(new Vector3($entry[0], $entry[1], $entry[2]));
            $level->setTime($this->getUniverseNightTime());
            $level->stopTime();
            $this->queueUniverseLandmarkChunks($level, $center);
            // Do not force-save every tick while async generation/population is
            // still running. repairUniverseLandmarkLighting() performs the
            // authoritative save once the 7x7 readiness gate is complete.
        }
        if($level === null || !$this->areUniverseLandmarkChunksReady($level, $center)){
            return false;
        }
        if(!$this->ensureUniverseEarthShell($level, $center)){
            return false;
        }
        return $this->repairUniverseLandmarkLighting($level, $center, false);
    }

    /** Universe must never become daytime, even if config contains a bad time. */
    private function getUniverseNightTime(){
        $time = (int) $this->getConfig()->getNested("space.time", 18000);
        $dayTime = (($time % 24000) + 24000) % 24000;
        if($dayTime < 12500 || $dayTime > 23500){
            return 18000;
        }
        return $time;
    }

    /**
     * Keep both the Level clock and MCPE 0.14's client clock at permanent
     * night. setTime()/stopTime() alone is not enough after chunk/world resets.
     */
    private function syncUniverseTime(Player $player = null){
        $level = $this->getServer()->getLevelByName($this->universeName);
        if($level === null){ return; }
        $time = $this->getUniverseNightTime();
        $level->setTime($time);
        $level->stopTime();
        if($player instanceof Player && $player->getLevel() === $level){
            $pk = new SetTimePacket();
            $pk->time = $time;
            $pk->started = false;
            $player->dataPacket($pk);
        }elseif(method_exists($level, "sendTime")){
            $level->sendTime();
        }
    }

    private function queueUniverseLandmarkChunks($level, array $center){
        $centerChunkX = ((int) floor($center[0])) >> 4;
        $centerChunkZ = ((int) floor($center[2])) >> 4;
        $generated = 0;
        $populationRequested = 0;

        // Generation is only the first half of Genisys' world pipeline. The
        // PopulationTask is what recalculates the height map + skylight and
        // marks a chunk populated/light-populated. Generate a 9x9 envelope so
        // every chunk in the inner 7x7 has all neighbouring columns available.
        for($chunkX = $centerChunkX - 4; $chunkX <= $centerChunkX + 4; ++$chunkX){
            for($chunkZ = $centerChunkZ - 4; $chunkZ <= $centerChunkZ + 4; ++$chunkZ){
                if(!$level->isChunkGenerated($chunkX, $chunkZ)){
                    $level->generateChunk($chunkX, $chunkZ, true);
                    ++$generated;
                }
            }
        }

        // Only the inner 7x7 is part of the landmark readiness gate.
        for($chunkX = $centerChunkX - 3; $chunkX <= $centerChunkX + 3; ++$chunkX){
            for($chunkZ = $centerChunkZ - 3; $chunkZ <= $centerChunkZ + 3; ++$chunkZ){
                if(!$level->isChunkGenerated($chunkX, $chunkZ)){
                    continue;
                }
                $level->loadChunk($chunkX, $chunkZ, true);
                if(!$level->isChunkPopulated($chunkX, $chunkZ)){
                    $level->populateChunk($chunkX, $chunkZ, true);
                    ++$populationRequested;
                }
            }
        }
        if(!$this->universeChunksQueued){
            $this->universeChunksQueued = true;
            $this->getLogger()->info("已安排 Universe 地标外围 9x9 生成、内部 7x7 population；完成前不会开放入口。 ");
        }elseif(($generated + $populationRequested) > 0 && (($generated + $populationRequested) % 16) === 1){
            $this->getLogger()->debug("Universe 地标仍在准备：generate=" . $generated . " populate=" . $populationRequested);
        }
    }

    private function areUniverseLandmarkChunksReady($level, array $center){
        $centerChunkX = ((int) floor($center[0])) >> 4;
        $centerChunkZ = ((int) floor($center[2])) >> 4;
        // Check the whole 7x7 preparation envelope, not just the Earth footprint
        // footprint.  This avoids entering while an edge chunk is still in the
        // async generation -> population handoff.
        for($chunkX = $centerChunkX - 3; $chunkX <= $centerChunkX + 3; ++$chunkX){
            for($chunkZ = $centerChunkZ - 3; $chunkZ <= $centerChunkZ + 3; ++$chunkZ){
                if(!$level->isChunkGenerated($chunkX, $chunkZ) || !$level->isChunkPopulated($chunkX, $chunkZ)){
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Convert the visual Earth from the original solid 64^3 voxel body to a
     * thin shell in-place. The user's uploaded real universe save proves the
     * solid asset and all saved chunks are complete; the disappearing stripe
     * moves when chunk alignment changes. The remaining common factor is the
     * extremely dense old-client chunk mesh (>10k Earth blocks in one column).
     *
     * A 3-block shell preserves every exterior colour/collision surface but
     * drops the densest Earth chunk from roughly 10.7k source blocks to about
     * 644 shell blocks, aggressively reducing packet entropy for MCPE 0.14.
     */
    private function ensureUniverseEarthShell($level, array $center, $force = false){
        $thickness = max(1, min(8, (int) $this->getConfig()->getNested("earth.shell-thickness", 1)));
        $marker = $this->getDataFolder() . "universe-earth-shell-v1.json";
        if(!$force && $this->universeEarthShellDone){
            return true;
        }
        if(!$force && is_file($marker)){
            $saved = json_decode((string) @file_get_contents($marker), true);
            if(is_array($saved) && (int) ($saved["version"] ?? 0) === 1
                && (int) ($saved["thickness"] ?? 0) === $thickness
                && isset($saved["center"]) && is_array($saved["center"])
                && (int) ($saved["center"][0] ?? 0) === (int) $center[0]
                && (int) ($saved["center"][1] ?? 0) === (int) $center[1]
                && (int) ($saved["center"][2] ?? 0) === (int) $center[2]){
                $this->universeEarthShellDone = true;
                return true;
            }
        }
        if(!$this->areUniverseLandmarkChunksReady($level, $center)){
            return false;
        }

        $assetPath = $this->getDataFolder() . basename((string) $this->getConfig()->getNested("earth.asset", "earth.bin.gz"));
        $asset = EarthAsset::load($assetPath);
        if(!($asset instanceof EarthAsset)){
            $this->getLogger()->error("无法读取 Earth UAE1 资源，不能执行 0.6.0 shell 渲染修复：" . $assetPath);
            return false;
        }
        $mask = $asset->getShellMask($thickness);
        list($width, $height, $length) = $asset->getSize();
        $originX = (int) $center[0] - (int) floor($width / 2);
        $originY = (int) $center[1] - (int) floor($height / 2);
        $originZ = (int) $center[2] - (int) floor($length / 2);
        $minChunkX = $originX >> 4;
        $maxChunkX = ($originX + $width - 1) >> 4;
        $minChunkZ = $originZ >> 4;
        $maxChunkZ = ($originZ + $length - 1) >> 4;
        $sourceNonAir = 0;
        $shellBlocks = 0;
        $removed = 0;
        $changed = 0;
        $touchedChunks = 0;

        for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
            for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
                $level->loadChunk($chunkX, $chunkZ, true);
                $level->populateChunk($chunkX, $chunkZ, true);
                $chunk = $level->getChunk($chunkX, $chunkZ, false);
                if($chunk === null){
                    return false;
                }
                ++$touchedChunks;
                $baseX = $chunkX << 4;
                $baseZ = $chunkZ << 4;
                for($lx = 0; $lx < 16; ++$lx){
                    $assetX = ($baseX + $lx) - $originX;
                    if($assetX < 0 || $assetX >= $width){ continue; }
                    for($lz = 0; $lz < 16; ++$lz){
                        $assetZ = ($baseZ + $lz) - $originZ;
                        if($assetZ < 0 || $assetZ >= $length){ continue; }
                        for($assetY = 0; $assetY < $height; ++$assetY){
                            $worldY = $originY + $assetY;
                            if($worldY < 0 || $worldY >= 128){ continue; }
                            $sourceId = $asset->getBlockId($assetX, $assetY, $assetZ);
                            $sourceMeta = $sourceId !== 0 ? $asset->getBlockData($assetX, $assetY, $assetZ) : 0;
                            if($sourceId !== 0){ ++$sourceNonAir; }
                            $keep = $sourceId !== 0 && $asset->isShellBlock($assetX, $assetY, $assetZ, $thickness, $mask);
                            $desiredId = $keep ? $sourceId : 0;
                            $desiredMeta = $keep ? $sourceMeta : 0;
                            if($keep){ ++$shellBlocks; }
                            elseif($sourceId !== 0){ ++$removed; }
                            $currentId = (int) $chunk->getBlockId($lx, $worldY, $lz);
                            $currentMeta = (int) $chunk->getBlockData($lx, $worldY, $lz);
                            if($currentId !== $desiredId || $currentMeta !== $desiredMeta){
                                $chunk->setBlockId($lx, $worldY, $lz, $desiredId);
                                $chunk->setBlockData($lx, $worldY, $lz, $desiredMeta);
                                ++$changed;
                            }
                        }
                    }
                }
                if(method_exists($chunk, "recalculateHeightMap")){ $chunk->recalculateHeightMap(); }
                if(method_exists($chunk, "populateSkyLight")){ $chunk->populateSkyLight(); }
                if(method_exists($chunk, "setLightPopulated")){ $chunk->setLightPopulated(true); }
                if(method_exists($chunk, "setPopulated")){ $chunk->setPopulated(true); }
                if(method_exists($chunk, "setChanged")){ $chunk->setChanged(); }
                if(method_exists($level, "clearChunkCache")){ $level->clearChunkCache($chunkX, $chunkZ); }
            }
        }
        $level->save(true);
        $this->universeEarthShellDone = true;
        @file_put_contents($marker, json_encode([
            "version" => 1,
            "center" => array_values($center),
            "thickness" => $thickness,
            "sourceNonAir" => $sourceNonAir,
            "shellBlocks" => $shellBlocks,
            "removedInterior" => $removed,
            "changedBlocks" => $changed,
            "chunks" => $touchedChunks,
            "updatedAt" => date("c")
        ]));
        $this->getLogger()->info("Earth 0.14 渲染降密度完成：solid=" . $sourceNonAir . " -> shell=" . $shellBlocks
            . "，移除内部=" . $removed . "，修改=" . $changed . "，覆盖=" . $touchedChunks . " chunks。外观表面保持不变。 ");
        foreach($level->getPlayers() as $player){
            if($player instanceof Player){
                $this->queueEarthChunkStream($player, 8);
            }
        }
        return true;
    }

    /**
     * One-time repair for Universe worlds created by 0.5.7 and earlier.
     * Those versions could save generated-but-not-light-populated landmark
     * chunks. Rebuild only metadata/lighting; never rewrite the actual blocks.
     */
    private function repairUniverseLandmarkLighting($level, array $center, $force = false){
        $marker = $this->getDataFolder() . "universe-lighting-v3.json";
        if(!$force && ($this->universeLightingRepairDone || is_file($marker))){
            $this->universeLightingRepairDone = true;
            return true;
        }
        if(!$this->areUniverseLandmarkChunksReady($level, $center)){
            return false;
        }

        $centerChunkX = ((int) floor($center[0])) >> 4;
        $centerChunkZ = ((int) floor($center[2])) >> 4;
        $repaired = 0;
        for($chunkX = $centerChunkX - 3; $chunkX <= $centerChunkX + 3; ++$chunkX){
            for($chunkZ = $centerChunkZ - 3; $chunkZ <= $centerChunkZ + 3; ++$chunkZ){
                $level->loadChunk($chunkX, $chunkZ, true);
                $chunk = $level->getChunk($chunkX, $chunkZ, false);
                if($chunk === null){
                    return false;
                }
                if(method_exists($chunk, "recalculateHeightMap")){
                    $chunk->recalculateHeightMap();
                }
                if(method_exists($chunk, "populateSkyLight")){
                    $chunk->populateSkyLight();
                }
                if(method_exists($chunk, "setLightPopulated")){
                    $chunk->setLightPopulated(true);
                }
                if(method_exists($chunk, "setPopulated")){
                    $chunk->setPopulated(true);
                }
                if(method_exists($chunk, "setChanged")){
                    $chunk->setChanged();
                }
                if(method_exists($level, "clearChunkCache")){
                    $level->clearChunkCache($chunkX, $chunkZ);
                }
                ++$repaired;
            }
        }
        $level->setTime($this->getUniverseNightTime());
        $level->stopTime();
        $level->save(true);
        $this->universeLightingRepairDone = true;
        @file_put_contents($marker, json_encode([
            "version" => 3,
            "center" => $center,
            "chunks" => $repaired,
            "updatedAt" => date("c")
        ]));
        $this->getLogger()->info("Universe 地标区块 population/高度图/天光修复完成：" . $repaired . " chunks。 ");

        // Do not burst 49 FullChunkData packets into MCPE 0.14. Earth is
        // streamed back one lightweight shell chunk at a time.
        foreach($level->getPlayers() as $player){
            if($player instanceof Player){
                $this->queueEarthChunkStream($player, 6);
                $this->syncUniverseTime($player);
            }
        }
        return true;
    }

    /** Create the invisible native carrier used for smooth space travel. */
    private function ensureSpaceCarrier(Player $player){
        if(!(bool) $this->getConfig()->getNested("space.carrier-enabled", true)){
            return false;
        }
        if($player->getLevel()->getFolderName() !== $this->universeName){
            return false;
        }
        $name = strtolower($player->getName());
        if(isset($this->dimensionTransitions[$name]) || isset($this->flights[$name])){
            return false;
        }
        if(isset($this->spaceCarriers[$name])){
            $carrier = $this->spaceCarriers[$name]["entity"];
            if($carrier instanceof SpaceCarrier && !$carrier->closed && $carrier->getLevel() === $player->getLevel()){
                // Mounting is deliberately completed by tickSpaceCarriers().
                // MCPE 0.14 can ignore a SetEntityLink sent in the same tick as
                // AddEntity. Keeping the entity alive for a few ticks first is
                // much more reliable than repeatedly recreating it here.
                return true;
            }
            $this->removeSpaceCarrier($player);
        }
        if($player->getLinkedEntity() instanceof Entity){
            return false;
        }
        $chunk = $player->getLevel()->getChunk(((int) floor($player->x)) >> 4, ((int) floor($player->z)) >> 4, true);
        if($chunk === null){
            return false;
        }
        $seatOffset = 0.55;
        $nbt = new CompoundTag("", [
            new ListTag("Pos", [new DoubleTag(0, $player->x), new DoubleTag(1, $player->y - $seatOffset), new DoubleTag(2, $player->z)]),
            new ListTag("Motion", [new DoubleTag(0, 0), new DoubleTag(1, 0), new DoubleTag(2, 0)]),
            new ListTag("Rotation", [new FloatTag(0, $player->yaw), new FloatTag(1, 0)])
        ]);
        $carrier = Entity::createEntity("SpaceCarrier", $chunk, $nbt);
        if(!($carrier instanceof SpaceCarrier)){
            return false;
        }

        // Spawn first, mount later. On 0.14, sending AddEntity + SetEntityLink in
        // one server tick can leave the server linked while the client keeps
        // falling as an unmounted player.
        $carrier->spawnToAll();
        $this->spaceCarriers[$name] = [
            "entity" => $carrier,
            "anchor" => [(float) $player->x, (float) $player->y, (float) $player->z],
            "seatOffset" => $seatOffset,
            "mountTicks" => 0,
            "mounted" => false,
            "linkResends" => 0,
            "linkResendTicks" => 0,
            "settleTicks" => 0,
            "motX" => 0.0,
            "motY" => 0.0,
            "jumping" => false,
            "sneaking" => false,
            "inputAt" => 0.0,
            "vx" => 0.0,
            "vy" => 0.0,
            "vz" => 0.0
        ];
        $player->setMotion(new Vector3(0, 0, 0));
        return true;
    }

    private function removeSpaceCarrier($playerOrName){
        $player = $playerOrName instanceof Player ? $playerOrName : $this->getServer()->getPlayerExact((string) $playerOrName);
        $name = $player instanceof Player ? strtolower($player->getName()) : strtolower((string) $playerOrName);
        if(!isset($this->spaceCarriers[$name])){
            return;
        }
        $carrier = $this->spaceCarriers[$name]["entity"];
        if($player instanceof Player && $carrier instanceof Entity && $player->getLinkedEntity() === $carrier){
            $player->setLinked(0, $carrier);
        }
        if($carrier instanceof Entity && !$carrier->closed){
            $carrier->close();
        }
        unset($this->spaceCarriers[$name]);
    }

    private function approachSpaceVelocity($current, $target, $amount){
        if($current < $target){
            return min($target, $current + $amount);
        }
        if($current > $target){
            return max($target, $current - $amount);
        }
        return $current;
    }

    /**
     * Vehicle-native space movement.
     *
     * 0.6.0 used Boat, but Genisys rewrites a linked Boat's position from each
     * rider MovePlayerPacket. That fights plugin-driven motion. 0.6.1 uses an
     * invisible Bat as a generic native riding seat, delays the first link until
     * the client has seen AddEntity, and resends the link a few times for old
     * MCPE clients which occasionally drop the first SetEntityLink packet.
     */
    private function tickSpaceCarriers($currentTick){
        $horizontalSpeed = max(0.05, min(1.50, (float) $this->getConfig()->getNested("space.carrier-horizontal-speed", 0.75)));
        $verticalSpeed = max(0.05, min(1.20, (float) $this->getConfig()->getNested("space.carrier-vertical-speed", 0.55)));
        $acceleration = max(0.005, min(0.50, (float) $this->getConfig()->getNested("space.carrier-acceleration", 0.14)));
        $deceleration = max(0.005, min(0.60, (float) $this->getConfig()->getNested("space.carrier-deceleration", 0.20)));
        $timeout = max(0.10, min(1.0, (float) $this->getConfig()->getNested("space.carrier-input-timeout", 0.35)));
        $deadzone = max(0.0, min(0.30, (float) $this->getConfig()->getNested("space.carrier-input-deadzone", 0.05)));
        $lookFlight = (bool) $this->getConfig()->getNested("space.carrier-look-flight", true);
        $mountDelay = max(2, min(20, (int) $this->getConfig()->getNested("space.carrier-mount-delay-ticks", 4)));
        $linkResends = max(0, min(8, (int) $this->getConfig()->getNested("space.carrier-link-resends", 3)));
        $linkInterval = max(1, min(10, (int) $this->getConfig()->getNested("space.carrier-link-resend-interval-ticks", 2)));
        $settleTicks = max(0, min(30, (int) $this->getConfig()->getNested("space.carrier-mount-settle-ticks", 8)));
        $now = microtime(true);

        foreach(array_keys($this->spaceCarriers) as $name){
            if(!isset($this->spaceCarriers[$name])){ continue; }
            $state = $this->spaceCarriers[$name];
            $player = $this->getServer()->getPlayerExact($name);
            $carrier = $state["entity"];
            if(!($player instanceof Player) || !($carrier instanceof SpaceCarrier) || $carrier->closed
                || $player->getLevel()->getFolderName() !== $this->universeName || isset($this->dimensionTransitions[$name])){
                $this->removeSpaceCarrier($player instanceof Player ? $player : $name);
                continue;
            }

            if(empty($state["mounted"])){
                $anchor = $state["anchor"];
                $seatOffset = (float) $state["seatOffset"];
                $carrier->setPosition(new Vector3($anchor[0], $anchor[1] - $seatOffset, $anchor[2]));
                $carrier->setMotion(new Vector3(0, 0, 0));

                // Hold the player only during the short AddEntity -> link
                // handshake. This prevents the visible "fall, then snap back"
                // without doing any continuous teleport-based movement.
                $player->setMotion(new Vector3(0, 0, 0));
                if(abs($player->x - $anchor[0]) > 0.02 || abs($player->y - $anchor[1]) > 0.02 || abs($player->z - $anchor[2]) > 0.02){
                    $player->setPosition(new Vector3($anchor[0], $anchor[1], $anchor[2]));
                }
                if(method_exists($player, "resetFallDistance")){
                    $player->resetFallDistance();
                }

                ++$this->spaceCarriers[$name]["mountTicks"];
                if((int) $this->spaceCarriers[$name]["mountTicks"] < $mountDelay){
                    continue;
                }
                if($player->getLinkedEntity() instanceof Entity && $player->getLinkedEntity() !== $carrier){
                    $this->removeSpaceCarrier($player);
                    continue;
                }
                if($player->getLinkedEntity() !== $carrier && !$player->linkEntity($carrier)){
                    // Do not loop a broken mount forever. Removing the carrier
                    // makes applyUniverseRules() fall back to ordinary free flight.
                    $this->removeSpaceCarrier($player);
                    continue;
                }
                $this->spaceCarriers[$name]["mounted"] = true;
                $this->spaceCarriers[$name]["linkResends"] = $linkResends;
                $this->spaceCarriers[$name]["linkResendTicks"] = 1;
                $this->spaceCarriers[$name]["settleTicks"] = $settleTicks;
                continue;
            }

            if($player->getLinkedEntity() !== $carrier){
                // A dropped client unlink is retried through the same delayed
                // handshake instead of teleporting the player every tick.
                if($player->getLinkedEntity() instanceof Entity){
                    $this->removeSpaceCarrier($player);
                    continue;
                }
                $this->spaceCarriers[$name]["mounted"] = false;
                $this->spaceCarriers[$name]["mountTicks"] = max(0, $mountDelay - 2);
                $this->spaceCarriers[$name]["anchor"] = [(float) $player->x, (float) $player->y, (float) $player->z];
                continue;
            }

            if((int) $state["linkResends"] > 0){
                --$this->spaceCarriers[$name]["linkResendTicks"];
                if((int) $this->spaceCarriers[$name]["linkResendTicks"] <= 0){
                    // Entity::sendLinkedData() re-emits SetEntityLink without
                    // changing the server-side relationship.
                    $player->sendLinkedData();
                    --$this->spaceCarriers[$name]["linkResends"];
                    $this->spaceCarriers[$name]["linkResendTicks"] = $linkInterval;
                }
            }

            if((int) $state["settleTicks"] > 0){
                // Give the client a short, stationary window to accept the
                // riding link before movement begins. This is only an entry
                // handshake, not the old continuous teleport multiplier.
                $anchor = $state["anchor"];
                $seatOffset = (float) $state["seatOffset"];
                $carrier->setPosition(new Vector3($anchor[0], $anchor[1] - $seatOffset, $anchor[2]));
                $carrier->setMotion(new Vector3(0, 0, 0));
                $player->setMotion(new Vector3(0, 0, 0));
                if(abs($player->x - $anchor[0]) > 0.05 || abs($player->y - $anchor[1]) > 0.05 || abs($player->z - $anchor[2]) > 0.05){
                    $player->setPosition(new Vector3($anchor[0], $anchor[1], $anchor[2]));
                }
                --$this->spaceCarriers[$name]["settleTicks"];
                continue;
            }

            $motX = (float) $state["motX"];
            $motY = (float) $state["motY"];
            $jumping = !empty($state["jumping"]);
            $sneaking = !empty($state["sneaking"]);
            if(($now - (float) $state["inputAt"]) > $timeout){
                $motX = 0.0;
                $motY = 0.0;
                $jumping = false;
                $sneaking = false;
            }
            if(abs($motX) < $deadzone){ $motX = 0.0; }
            if(abs($motY) < $deadzone){ $motY = 0.0; }
            $motX = max(-1.0, min(1.0, $motX));
            $motY = max(-1.0, min(1.0, $motY));

            $yaw = deg2rad((float) $player->yaw);
            $pitch = deg2rad((float) $player->pitch);
            $pitchCos = $lookFlight ? cos($pitch) : 1.0;
            $forwardX = -sin($yaw) * $pitchCos;
            $forwardY = $lookFlight ? -sin($pitch) : 0.0;
            $forwardZ = cos($yaw) * $pitchCos;
            $rightX = cos($yaw);
            $rightZ = sin($yaw);
            $dirX = ($forwardX * $motY) + ($rightX * $motX);
            $dirZ = ($forwardZ * $motY) + ($rightZ * $motX);
            $horizontalLength = sqrt(($dirX * $dirX) + ($dirZ * $dirZ));
            if($horizontalLength > 1.0){
                $dirX /= $horizontalLength;
                $dirZ /= $horizontalLength;
            }
            $targetX = $dirX * $horizontalSpeed;
            $targetZ = $dirZ * $horizontalSpeed;
            $vertical = ($jumping ? 1 : 0) - ($sneaking ? 1 : 0);
            $targetY = ($forwardY * $motY * $horizontalSpeed) + ($vertical * $verticalSpeed);
            $targetY = max(-$verticalSpeed, min($verticalSpeed, $targetY));

            $ax = abs($targetX) > 0.0001 ? $acceleration : $deceleration;
            $ay = abs($targetY) > 0.0001 ? $acceleration : $deceleration;
            $az = abs($targetZ) > 0.0001 ? $acceleration : $deceleration;
            $vx = $this->approachSpaceVelocity((float) $state["vx"], $targetX, $ax);
            $vy = $this->approachSpaceVelocity((float) $state["vy"], $targetY, $ay);
            $vz = $this->approachSpaceVelocity((float) $state["vz"], $targetZ, $az);
            $this->spaceCarriers[$name]["vx"] = $vx;
            $this->spaceCarriers[$name]["vy"] = $vy;
            $this->spaceCarriers[$name]["vz"] = $vz;
            $carrier->yaw = $player->yaw;
            $carrier->pitch = 0;
            $carrier->setMotion(new Vector3($vx, $vy, $vz));
        }
    }


    private function ensureSpaceFlightAssistState(Player $player){
        $name = strtolower($player->getName());
        if(!isset($this->spaceFlightAssist[$name])){
            $now = microtime(true);
            $this->spaceFlightAssist[$name] = [
                "forward" => 0.0,
                "strafe" => 0.0,
                "yaw" => (float) $player->yaw,
                "pitch" => (float) $player->pitch,
                "inputAt" => 0.0,
                "eventAt" => $now,
                "vx" => 0.0,
                "vy" => 0.0,
                "vz" => 0.0,
                "observedVy" => 0.0,
                "verticalAt" => $now,
                "sneaking" => false,
                "packetForward" => 0.0,
                "packetStrafe" => 0.0,
                "packetInputAt" => 0.0
            ];
        }
    }

    public function onPlayerToggleSneak(PlayerToggleSneakEvent $event){
        $player = $event->getPlayer();
        if($player->getLevel()->getFolderName() !== $this->universeName){
            return;
        }
        $name = strtolower($player->getName());
        if(isset($this->dimensionTransitions[$name]) || isset($this->flights[$name])){
            return;
        }
        $this->ensureSpaceFlightAssistState($player);
        $this->spaceFlightAssist[$name]["sneaking"] = (bool) $event->isSneaking();
    }

    /**
     * Learn horizontal control intent from the normal MovePlayer stream.
     *
     * Gravity is deliberately ignored here. This prevents ordinary falling
     * from being mistaken for "fly downward". Forward/strafe intent is derived
     * from X/Z residual movement after subtracting the velocity we previously
     * injected. Pitch is then used by tickSpaceFlightAssist() to turn forward
     * motion into true 3D flight.
     */
    public function onPlayerMove(PlayerMoveEvent $event){
        $player = $event->getPlayer();
        if(!(bool) $this->getConfig()->getNested("space.flight-assist-enabled", true)){
            return;
        }
        if($player->getLevel()->getFolderName() !== $this->universeName){
            return;
        }
        $name = strtolower($player->getName());
        if(isset($this->dimensionTransitions[$name]) || isset($this->flights[$name])){
            return;
        }

        // Important: do not use DATA_NO_AI on Player. It freezes movement on
        // some MCPE 0.14 builds. This changes only a protected server counter;
        // no packet or metadata is sent to the client.
        $this->suppressCoreAntiFlyCounter($player);
        $this->ensureSpaceFlightAssistState($player);
        $state = $this->spaceFlightAssist[$name];
        $from = $event->getFrom();
        $to = $event->getTo();
        $dx = (float) ($to->x - $from->x);
        $dz = (float) ($to->z - $from->z);
        $horizontal = sqrt(($dx * $dx) + ($dz * $dz));
        $maxStep = max(0.10, min(2.0, (float) $this->getConfig()->getNested("space.flight-assist-max-source-step", 0.80)));
        $now = microtime(true);
        $dtTicks = max(0.5, min(3.0, ($now - (float) $state["eventAt"]) * 20.0));
        $this->spaceFlightAssist[$name]["eventAt"] = $now;
        $this->spaceFlightAssist[$name]["yaw"] = (float) $to->yaw;
        $this->spaceFlightAssist[$name]["pitch"] = (float) $to->pitch;

        if($horizontal <= 0.0003 || $horizontal > $maxStep){
            return;
        }

        // 1.0.1-style input extraction: subtract our previous velocity so
        // inertial drift is not mistaken for a held movement key. Unlike 1.0.1
        // we deliberately allow reverse input so a 180-degree turn responds.
        $rx = $dx - ((float) $state["vx"] * $dtTicks);
        $rz = $dz - ((float) $state["vz"] * $dtTicks);
        $residual = sqrt(($rx * $rx) + ($rz * $rz));
        $threshold = max(0.002, min(0.10, (float) $this->getConfig()->getNested("space.flight-assist-input-threshold", 0.012)));
        if($residual < $threshold){
            return;
        }

        $yaw = deg2rad((float) $to->yaw);
        $forwardX = -sin($yaw);
        $forwardZ = cos($yaw);
        $rightX = cos($yaw);
        $rightZ = sin($yaw);
        $forward = ($rx * $forwardX) + ($rz * $forwardZ);
        $strafe = ($rx * $rightX) + ($rz * $rightZ);
        $inputLength = sqrt(($forward * $forward) + ($strafe * $strafe));
        if($inputLength < $threshold){
            return;
        }
        $this->spaceFlightAssist[$name]["forward"] = $forward / $inputLength;
        $this->spaceFlightAssist[$name]["strafe"] = $strafe / $inputLength;
        $this->spaceFlightAssist[$name]["inputAt"] = $now;
    }

    /**
     * Calm 0.14 spacewalk controller.
     *
     * Legacy fallback controller retained for compatibility only. 1.0.10 ships
     * with flight-assist-enabled=false and uses MCPE native flight in Universe.
     * This Motion path is therefore dormant by default.
     */
    private function tickSpaceFlightAssist($currentTick){
        if(!(bool) $this->getConfig()->getNested("space.flight-assist-enabled", true)){
            $this->spaceFlightAssist = [];
            return;
        }
        $maxSpeed = max(0.05, min(1.20, (float) $this->getConfig()->getNested("space.flight-assist-max-speed", 0.54)));
        $verticalSpeed = max(0.05, min(1.20, (float) $this->getConfig()->getNested("space.flight-assist-vertical-speed", 0.54)));
        $acceleration = max(0.005, min(0.30, (float) $this->getConfig()->getNested("space.flight-assist-acceleration", 0.065)));
        $deceleration = max(0.002, min(0.30, (float) $this->getConfig()->getNested("space.flight-assist-deceleration", 0.025)));
        $turnAcceleration = max($acceleration, min(0.35, (float) $this->getConfig()->getNested("space.flight-assist-turn-acceleration", 0.14)));
        $verticalAcceleration = max(0.01, min(0.40, (float) $this->getConfig()->getNested("space.flight-assist-vertical-acceleration", 0.085)));
        $sneakAcceleration = max($verticalAcceleration, min(0.50, (float) $this->getConfig()->getNested("space.flight-assist-sneak-acceleration", 0.24)));
        $verticalIdleBrake = max(0.01, min(0.30, (float) $this->getConfig()->getNested("space.flight-assist-vertical-idle-brake", 0.07)));
        $timeout = max(0.08, min(0.80, (float) $this->getConfig()->getNested("space.flight-assist-input-timeout", 0.26)));
        $pitchDeadzone = max(0.0, min(30.0, (float) $this->getConfig()->getNested("space.flight-assist-view-pitch-deadzone", 6.0)));
        $interval = max(1, min(4, (int) $this->getConfig()->getNested("space.flight-assist-send-interval-ticks", 1)));
        $now = microtime(true);

        foreach($this->getServer()->getOnlinePlayers() as $player){
            if($player->getLevel()->getFolderName() !== $this->universeName){
                continue;
            }
            $name = strtolower($player->getName());
            if(isset($this->dimensionTransitions[$name]) || isset($this->flights[$name])){
                $this->clearSpaceFlightAssist($player);
                continue;
            }
            $this->suppressCoreAntiFlyCounter($player);
            $this->ensureSpaceFlightAssistState($player);
            $state = $this->spaceFlightAssist[$name];
            $active = ($now - (float) $state["inputAt"]) <= $timeout;

            $targetX = 0.0;
            $targetY = 0.0;
            $targetZ = 0.0;
            if($active){
                $yaw = deg2rad((float) $state["yaw"]);
                $pitchDegrees = max(-89.0, min(89.0, (float) $state["pitch"]));
                if(abs($pitchDegrees) <= $pitchDeadzone){
                    $pitchDegrees = 0.0;
                }
                $pitch = deg2rad($pitchDegrees);
                $forward = (float) $state["forward"];
                $strafe = (float) $state["strafe"];
                $pitchCos = cos($pitch);
                $forwardX = -sin($yaw) * $pitchCos;
                $forwardY = -sin($pitch);
                $forwardZ = cos($yaw) * $pitchCos;
                $rightX = cos($yaw);
                $rightZ = sin($yaw);
                $dirX = ($forwardX * $forward) + ($rightX * $strafe);
                $dirY = $forwardY * $forward;
                $dirZ = ($forwardZ * $forward) + ($rightZ * $strafe);
                $length = sqrt(($dirX * $dirX) + ($dirY * $dirY) + ($dirZ * $dirZ));
                if($length > 0.0001){
                    $dirX /= $length;
                    $dirY /= $length;
                    $dirZ /= $length;
                }
                $targetX = $dirX * $maxSpeed;
                $targetY = $dirY * $verticalSpeed;
                $targetZ = $dirZ * $maxSpeed;
            }
            $sneaking = !empty($state["sneaking"]);
            if($sneaking){
                $targetY = -$verticalSpeed;
            }

            $oldVx = (float) $state["vx"];
            $oldVz = (float) $state["vz"];
            $horizontalStep = $active ? $acceleration : $deceleration;
            if($active){
                $oldLen = sqrt(($oldVx * $oldVx) + ($oldVz * $oldVz));
                $targetLen = sqrt(($targetX * $targetX) + ($targetZ * $targetZ));
                if($oldLen > 0.02 && $targetLen > 0.02){
                    $cosTurn = (($oldVx * $targetX) + ($oldVz * $targetZ)) / ($oldLen * $targetLen);
                    if($cosTurn < 0.0){
                        $horizontalStep = $turnAcceleration;
                    }
                }
            }
            $vx = $this->approachSpaceVelocity($oldVx, $targetX, $horizontalStep);
            $vz = $this->approachSpaceVelocity($oldVz, $targetZ, $horizontalStep);
            if($sneaking){
                $verticalStep = $sneakAcceleration;
            }elseif($active){
                $verticalStep = $verticalAcceleration;
            }else{
                $verticalStep = $verticalIdleBrake;
            }
            $vy = $this->approachSpaceVelocity((float) $state["vy"], $targetY, $verticalStep);
            if(abs($vx) < 0.002){ $vx = 0.0; }
            if(abs($vy) < 0.002){ $vy = 0.0; }
            if(abs($vz) < 0.002){ $vz = 0.0; }
            $this->spaceFlightAssist[$name]["vx"] = $vx;
            $this->spaceFlightAssist[$name]["vy"] = $vy;
            $this->spaceFlightAssist[$name]["vz"] = $vz;

            if(($currentTick % $interval) === 0){
                if(!$player->isCreative() || $active || $sneaking || abs($vx) + abs($vy) + abs($vz) > 0.003){
                    $player->setMotion(new Vector3($vx, $vy, $vz));
                }
                if(method_exists($player, "resetFallDistance")){
                    $player->resetFallDistance();
                }
            }
        }

        foreach(array_keys($this->spaceFlightAssist) as $name){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player) || $player->getLevel()->getFolderName() !== $this->universeName){
                unset($this->spaceFlightAssist[$name]);
            }
        }
    }

    /**
     * Per-player Genisys 0.14 anti-fly bypass without touching AdventureSettings
     * or entity metadata. Player::onUpdate() only applies its anti-fly correction
     * when inAirTicks > 10; keeping this protected counter negative prevents that
     * branch while preserving normal client movement and survival gamemode.
     */
    private function suppressCoreAntiFlyCounter(Player $player){
        if($player->isCreative() || !(bool) $this->getConfig()->getNested("space.antifly-counter-bypass", true)){
            return;
        }
        if($this->inAirTicksReflectionFailed){
            return;
        }
        try{
            if(!($this->inAirTicksProperty instanceof \ReflectionProperty)){
                $property = new \ReflectionProperty(Player::class, "inAirTicks");
                $property->setAccessible(true);
                $this->inAirTicksProperty = $property;
            }
            $this->inAirTicksProperty->setValue($player, -100);
        }catch(\Throwable $e){
            $this->inAirTicksReflectionFailed = true;
            if(!$this->inAirTicksReflectionWarned){
                $this->inAirTicksReflectionWarned = true;
                $this->getLogger()->warning("无法访问 Player::inAirTicks；Universe anti-fly 独立绕过不可用：" . $e->getMessage());
            }
        }
    }

    private function releaseCoreAntiFlyCounter(Player $player){
        if($this->inAirTicksReflectionFailed){
            return;
        }
        try{
            if($this->inAirTicksProperty instanceof \ReflectionProperty){
                $this->inAirTicksProperty->setValue($player, 0);
            }
        }catch(\Throwable $e){
            // Non-fatal; normal ground contact will reset this counter in core.
        }
    }

    private function clearSpaceFlightAssist($playerOrName){
        $name = $playerOrName instanceof Player ? strtolower($playerOrName->getName()) : strtolower((string) $playerOrName);
        unset($this->spaceFlightAssist[$name]);
    }

    private function applyUniverseRules(Player $player, $tick){
        $name = strtolower($player->getName());
        // 1.0.10 returns Universe movement to MCPE's native flight physics.
        // Server-side Motion controllers all produced visible camera corrections
        // on this old client. Survival/adventure therefore receive temporary
        // allowFlight here; the posture is removed later on a real Nether deck
        // by an inventory-safe gamemode pulse plus verified ground contact.
        $nativeSurvivalFlight = (bool) $this->getConfig()->getNested("space.survival-native-flight", true);
        $this->enterCustomRules($player, $player->isCreative() || $nativeSurvivalFlight, (bool) $this->getConfig()->getNested("rules.space-night-vision", true));
        if($player->y < 0){
            $entry = $this->getUniverseEarthEntry();
            $player->teleport(new Position($entry[0], $entry[1], $entry[2], $player->getLevel()));
            return;
        }
        if($this->isCoolingDown($name)){
            return;
        }
        $earth = $this->getUniverseEarthCenter();
        $dx = $player->x - $earth[0];
        $dy = $player->y - $earth[1];
        $dz = $player->z - $earth[2];
        $distance = (float) $this->getConfig()->getNested("earth.trigger-distance", 18);
        if(($dx * $dx) + ($dy * $dy) + ($dz * $dz) <= ($distance * $distance)){
            $this->setCooldown($name);
            $this->returnToConfiguredWorld($player);
            return;
        }
        if(($tick % 4) !== 0){
            return;
        }
        $sphere = UniverseLayout::findTouchingSphere($player->getLevel()->getSeed(), $player->x, $player->y, $player->z, $this->layout);
        if($sphere !== null){
            $this->setCooldown($name);
            $this->enterPlanet($player, $sphere);
        }
    }

    private function applyPlanetRules(Player $player, $world, $tick){
        $this->releaseCoreAntiFlyCounter($player);
        $planet = $this->registry->getByWorld($world);
        if($planet !== null){
            $this->applyPlanetGravity($player, $world, $planet);
            // Legacy wool planets were generated as almost solid wool columns.
            // Earth testing showed that high-density wool chunks can make MCPE
            // 0.14 drop a whole rendered column even though the chunk is correct
            // on disk. Compact newly visited wool chunks to a visible shell.
            if((int) ($planet["block"]["id"] ?? 0) === Block::WOOL && ($tick % 5) === 0){
                $this->stabilizeWoolPlanetArea($player->getLevel(), $planet, ((int) floor($player->x)) >> 4, ((int) floor($player->z)) >> 4, 1, $player);
            }
        }
        $nightVision = (bool) $this->getConfig()->getNested("rules.planet-night-vision", false);
        if($planet !== null && !empty($planet["visual"]["nightVision"])){
            $nightVision = true;
        }
        $inFlight = isset($this->flights[strtolower($player->getName())]);
        // Survival/adventure players only receive plugin flight while actually
        // riding an aircraft. Creative mode keeps its vanilla flight permission
        // everywhere; UniverseAdventure must never downgrade the game mode's
        // own movement abilities.
        $this->enterCustomRules($player, $inFlight || $player->isCreative(), $nightVision);
        if($planet !== null && ($tick % 100) === 0){
            $visual = $planet["visual"];
            $timeMode = (string) ($visual["timeMode"] ?? "cycle");
            if($timeMode !== "cycle"){
                $player->getLevel()->setTime((int) ($visual["time"] ?? ($timeMode === "day" ? 6000 : 18000)));
                $player->getLevel()->stopTime();
            }
            if(($visual["weather"] ?? "clear") === "cycle"){
                $this->updatePlanetWeatherCycle($player->getLevel(), $planet);
            }
        }
        if($player->y < 0){
            $spawnY = $planet !== null ? $this->getPlanetSpawnY($planet) : 110;
            $player->teleport(new Position(8.5, $spawnY, 8.5, $player->getLevel()));
        }
        unset($this->unloadAt[$world]);
    }

    private function enterCustomRules(Player $player, $allowFlight, $nightVision){
        $name = strtolower($player->getName());
        if(!isset($this->customPlayers[$name])){
            $this->customPlayers[$name] = true;
            $this->originalFlight[$name] = $player->getAllowFlight();
            $this->originalNightVision[$name] = $player->hasEffect(Effect::NIGHT_VISION) ? clone $player->getEffect(Effect::NIGHT_VISION) : null;
        }
        $desiredFlight = (bool) $allowFlight || $player->isCreative();
        if($player->getAllowFlight() !== $desiredFlight){
            $player->setAllowFlight($desiredFlight);
        }
        if($nightVision){
            if(!$player->hasEffect(Effect::NIGHT_VISION)){
                $effect = Effect::getEffect(Effect::NIGHT_VISION);
                // 接近 int32 上限，整个在线周期都不会进入客户端的闪烁阶段。
                $effect->setDuration(2147480000)->setAmplifier(0)->setVisible(false);
                $player->addEffect($effect);
            }
        }elseif(isset($this->originalNightVision[$name]) && $this->originalNightVision[$name] === null && $player->hasEffect(Effect::NIGHT_VISION)){
            $player->removeEffect(Effect::NIGHT_VISION);
        }
    }

    private function leaveCustomRules(Player $player, $deferFlightRestore = false){
        $this->releaseCoreAntiFlyCounter($player);
        $this->removeSpaceCarrier($player);
        $this->clearSpaceFlightAssist($player);
        $this->clearPlanetGravity($player);
        $name = strtolower($player->getName());
        if(!isset($this->customPlayers[$name])){
            return $player->isCreative() || $player->getAllowFlight();
        }
        $restoreFlight = (isset($this->originalFlight[$name]) ? (bool) $this->originalFlight[$name] : false) || $player->isCreative();
        if(!$deferFlightRestore){
            $player->setAllowFlight($restoreFlight);
        }
        $player->removeEffect(Effect::NIGHT_VISION);
        if(isset($this->originalNightVision[$name]) && $this->originalNightVision[$name] instanceof Effect){
            $player->addEffect($this->originalNightVision[$name]);
        }
        unset($this->customPlayers[$name], $this->originalFlight[$name], $this->originalNightVision[$name]);
        return $restoreFlight;
    }

    private function applyPlanetGravity(Player $player, $world, array $planet){
        $name = strtolower($player->getName());
        if(isset($this->planetGravityWorld[$name]) && $this->planetGravityWorld[$name] === strtolower($world)){
            return;
        }
        $this->clearPlanetGravity($player);
        $this->planetGravityWorld[$name] = strtolower($world);
        $this->originalGravityEffects[$name] = [];
        foreach([Effect::JUMP, Effect::SPEED, Effect::SLOWNESS] as $effectId){
            $this->originalGravityEffects[$name][$effectId] = $player->hasEffect($effectId) ? clone $player->getEffect($effectId) : null;
            if($player->hasEffect($effectId)){
                $player->removeEffect($effectId);
            }
        }

        $gravity = (array) ($planet["visual"]["gravity"] ?? []);
        $effects = [];
        if(isset($gravity["jump"])){
            $effects[Effect::JUMP] = (int) $gravity["jump"];
        }
        if(isset($gravity["speed"])){
            $effects[Effect::SPEED] = (int) $gravity["speed"];
        }elseif(isset($gravity["slowness"])){
            // speed 与 slowness 永远走互斥分支，不会同时施加。
            $effects[Effect::SLOWNESS] = (int) $gravity["slowness"];
        }
        foreach($effects as $effectId => $amplifier){
            $effect = Effect::getEffect($effectId);
            if($effect !== null){
                $effect->setDuration(2147480000)->setAmplifier($amplifier)->setVisible(false);
                $player->addEffect($effect);
            }
        }
    }

    private function clearPlanetGravity(Player $player){
        $name = strtolower($player->getName());
        if(!isset($this->planetGravityWorld[$name])){
            return;
        }
        foreach([Effect::JUMP, Effect::SPEED, Effect::SLOWNESS] as $effectId){
            if($player->hasEffect($effectId)){
                $player->removeEffect($effectId);
            }
            if(isset($this->originalGravityEffects[$name][$effectId]) && $this->originalGravityEffects[$name][$effectId] instanceof Effect){
                $player->addEffect($this->originalGravityEffects[$name][$effectId]);
            }
        }
        unset($this->planetGravityWorld[$name], $this->originalGravityEffects[$name]);
    }

    private function getGravityDisplayName(array $gravity){
        $type = (string) ($gravity["type"] ?? "normal");
        return $type === "low" ? "低重力" : ($type === "high" ? "高重力" : "标准重力");
    }

    private function sendPlanetReport(Player $player, array $planet){
        $visual = (array) ($planet["visual"] ?? []);
        $gravity = (array) ($visual["gravity"] ?? []);
        $gravityNames = ["normal" => "标准", "low" => "较低", "high" => "较高"];
        $timeNames = ["cycle" => "昼夜循环", "day" => "永昼", "night" => "永夜"];
        $weatherNames = ["cycle" => "晴雨循环", "clear" => "无雨", "rain" => "永雨"];
        $climateNames = ["normal" => "普通", "arid" => "干旱", "ocean" => "海洋"];
        $displayName = (string) ($planet["displayName"] ?? $planet["world"]);
        $owner = (string) ($planet["nameOwner"] ?? "");
        $surface = (string) ($planet["block"]["name"] ?? "unknown");
        $player->sendMessage(TextFormat::AQUA . "星球：" . $displayName . TextFormat::GRAY . "（" . $planet["world"] . "）");
        if($owner !== ""){
            $player->sendMessage(TextFormat::GRAY . "命名者：" . $owner);
        }
        $player->sendMessage(TextFormat::GRAY . "地表：" . $surface . " | 地貌：" . ($climateNames[$visual["climate"] ?? "normal"] ?? "未知") . " | 海平面：" . (int) ($visual["seaLevel"] ?? 63));
        $player->sendMessage(TextFormat::GRAY . "重力：" . ($gravityNames[$gravity["type"] ?? "normal"] ?? "标准") . " | 明暗：" . (!empty($visual["nightVision"]) ? "明亮" : "自然灰暗"));
        $player->sendMessage(TextFormat::GRAY . "昼夜：" . ($timeNames[$visual["timeMode"] ?? "cycle"] ?? "昼夜循环") . " | 降水：" . ($weatherNames[$visual["weather"] ?? "clear"] ?? "无雨") . (!empty($visual["frozenOcean"]) ? " | 冰封水面" : ""));
        $player->sendMessage(TextFormat::GOLD . "优势矿种：" . $this->getDominantOres((array) ($planet["ores"] ?? [])));
    }

    private function getDominantOres(array $ores){
        $names = [
            14 => "金矿", 15 => "铁矿", 16 => "煤矿", 21 => "青金石矿",
            56 => "钻石矿", 73 => "红石矿", 74 => "红石矿", 129 => "绿宝石矿", 153 => "下界石英矿"
        ];
        usort($ores, function($a, $b){
            $scoreA = (int) ($a["count"] ?? 0) * (int) ($a["size"] ?? 0);
            $scoreB = (int) ($b["count"] ?? 0) * (int) ($b["size"] ?? 0);
            return $scoreA === $scoreB ? 0 : ($scoreA > $scoreB ? -1 : 1);
        });
        $result = [];
        foreach(array_slice($ores, 0, 3) as $index => $ore){
            $result[] = ($index + 1) . "." . ($names[(int) ($ore["id"] ?? 0)] ?? ("矿物#" . (int) ($ore["id"] ?? 0)));
        }
        return count($result) > 0 ? implode("  ", $result) : "无";
    }

    private function showSpaceWalkTutorial(Player $player){
        $player->sendPopup(TextFormat::AQUA . "太空行走", TextFormat::WHITE . "原版飞行：双击跳跃起飞 | WASD移动 | 上升/下降键控高度");
        $player->sendMessage(TextFormat::AQUA . "[太空行走] " . TextFormat::WHITE . "Universe 使用 MCPE 原版飞行物理：双击跳跃进入/退出飞行，WASD 与原版升降键自由前往星球。离开宇宙时会先在 Nether 高空黑曜石台落地确认飞行态已清除，再进入星球或地球。");
    }

    private function sendEarthReport(Player $player){
        $player->sendMessage(TextFormat::GREEN . "星球：地球");
        $player->sendMessage(TextFormat::GRAY . "地貌：原版普通 | 重力：标准 | 明暗：自然");
        $player->sendMessage(TextFormat::GRAY . "昼夜：循环 | 降水：已关闭（0.14 核心雷电兼容保护）");
    }

    /**
     * Flush MCPE 0.14's coordinate-only chunk/sky cache through a real Nether
     * world switch.  Different normal worlds all report dimension 0, so the
     * legacy core will not send ChangeDimensionPacket when switching directly
     * between them.  Always force non-Nether cross-world travel through the
     * actual Nether world instead of relying on the worlds' dimension IDs.
     */
    private function teleportAcrossWorld(Player $player, Position $target){
        $targetLevel = $target->getLevel();
        if($targetLevel === null){
            return false;
        }

        // Never let a second proximity/command trigger overwrite an in-flight
        // two-hop transition. In particular, a re-entry while the temporary
        // gamemode pulse is active could otherwise discard originalGamemode and
        // jump straight from a dimension-0 world to another dimension-0 world.
        $transitionName = strtolower($player->getName());
        if(isset($this->dimensionTransitions[$transitionName])){
            $existing = $this->dimensionTransitions[$transitionName];
            $existingTarget = isset($existing["target"]) && $existing["target"] instanceof Position ? $existing["target"] : null;
            if($existingTarget instanceof Position && $existingTarget->getLevel() === $targetLevel){
                return true;
            }
            $player->sendMessage(TextFormat::YELLOW . "维度中转仍在进行，请等待当前传送完成。");
            return false;
        }

        if($player->getLevel() === $targetLevel){
            $ok = $player->teleport($target);
            if($ok){ $this->queueWorldResync($player); }
            return $ok;
        }

        $sourceWorld = strtolower($player->getLevel()->getFolderName());
        $targetWorld = strtolower($targetLevel->getFolderName());
        if($sourceWorld === strtolower($this->universeName) && $targetWorld !== $sourceWorld){
            $this->removeSpaceCarrier($player);
            $this->clearSpaceFlightAssist($player);
        }

        $netherName = strtolower((string) $this->getConfig()->getNested("dimension-transfer.nether-world", "nether"));

        // Experimental client-only Earth relay retained for compatibility, but
        // disabled by default in 1.0.5. Rapid cycles can leave MCPE 0.14 inside
        // the synthetic all-air scene. The stable release path physically enters
        // Nether and therefore gets the core's genuine 0 -> 1 -> 0 dimension
        // lifecycle, exactly like the already-stable planet route.
        $virtualEarth = (bool) $this->getConfig()->getNested("dimension-transfer.virtual-earth-relay", false)
            && $sourceWorld === strtolower($this->universeName)
            && $this->isEarthWorldName($targetWorld);
        if($virtualEarth){
            $name = strtolower($player->getName());
            $this->dimensionTransitions[$name] = [
                "relayMode" => "virtual",
                "target" => $target,
                "started" => microtime(true),
                "targetPrepared" => false,
                "earthTarget" => true,
                "revokeFlightInNether" => !$player->isCreative(),
                "virtualStarted" => false,
                "virtualTicks" => 0,
                "virtualHold" => [(float) $player->x, (float) $player->y, (float) $player->z],
                "virtualSourceWorld" => $sourceWorld
            ];
            $this->persistDimensionTarget($name, $target);

            if($this->prepareDimensionTarget($name)){
                $this->beginVirtualDimensionRelay($player, $name);
            }else{
                $player->sendMessage(TextFormat::GRAY . "正在生成地球落地区域，完成后进行虚拟 Nether 缓存刷新……");
            }
            return true;
        }

        // If one endpoint really is the Nether, let the core perform the single
        // real dimension switch. Never add a second synthetic dimension packet.
        if($sourceWorld === $netherName || $targetWorld === $netherName){
            $ok = $player->teleport($target);
            if($ok){ $this->queueWorldResync($player); }
            return $ok;
        }

        $nether = $this->getNetherLevel();
        if($nether === null){
            $this->getLogger()->warning("Cannot perform safe 0.14 cross-world transfer: the configured Nether world is unavailable or does not match this core's Nether dimension ID.");
            $player->sendMessage(TextFormat::RED . "Nether 中转世界不可用或维度 ID 与当前核心的 Nether 定义不一致，本次跨世界传送已取消。\n§7不会直接跳过中转，以免留下红色天空。");
            return false;
        }

        $name = strtolower($player->getName());
        $this->dimensionTransitions[$name] = [
            "relayMode" => "real",
            "target" => $target,
            "started" => microtime(true),
            "retryAt" => 0.0,
            "targetPrepared" => false,
            "netherAttempted" => false,
            "netherStarted" => 0.0,
            "enteredNether" => false,
            "netherEnteredAt" => 0.0,
            "netherTerrainReady" => false,
            "netherChunks" => [],
            "netherWaitNotice" => false,
            "serverReady" => false,
            "netherTicks" => 0,
            "netherClientSettleTicks" => 0,
            "netherClientResyncPasses" => 0,
            "sourceWorld" => $player->getLevel()->getFolderName(),
            "sourceX" => (float) $player->x,
            "sourceY" => (float) $player->y,
            "sourceZ" => (float) $player->z,
            "sourceYaw" => (float) $player->yaw,
            "sourcePitch" => (float) $player->pitch,
            "earthTarget" => $this->isEarthWorldName($targetWorld),
            "revokeFlightInNether" => !$player->isCreative() && $targetWorld !== strtolower($this->universeName),
            "relayFlightRevoked" => false,
            "relayClientResetSent" => false,
            "originalGamemode" => (int) $player->getGamemode(),
            "nativeResetStage" => "waiting",
            "nativeResetTicks" => 0,
            "nativeResetAttempts" => 0,
            "nativeGroundTicks" => 0,
            "nativeFallStartY" => null,
            "nativeObservedFall" => false,
            "nativeResetComplete" => false,
            "nativeInventorySnapshot" => null
        ];
        $this->persistDimensionTarget($name, $target);

        if($this->prepareDimensionTarget($name)){
            $this->prepareNetherTransferPad($nether);
            $this->attemptNetherTransfer($player, $nether);
        }else{
            $player->sendMessage(TextFormat::GRAY . "正在生成目标落地区域，完成后再进入 Nether 中转……");
        }
        return true;
    }

    /**
     * Client-only dimension scrub for Universe -> Earth.
     *
     * The server player deliberately remains in Universe. The client is told to
     * enter dimension 1, receives a tiny 3x3 all-air scene and a MODE_RESET, then
     * returns to dimension 0 after a short tick-driven dwell. This mirrors the
     * workaround commonly used by old MCPE multiworld plugins without blocking
     * the server thread or loading real Nether chunks.
     */
    private function beginVirtualDimensionRelay(Player $player, $name){
        if(!isset($this->dimensionTransitions[$name])){
            return false;
        }
        if(($this->dimensionTransitions[$name]["relayMode"] ?? "") !== "virtual"){
            return false;
        }
        if(empty($this->dimensionTransitions[$name]["targetPrepared"])){
            return false;
        }
        if(!empty($this->dimensionTransitions[$name]["virtualStarted"])){
            return true;
        }

        $this->clearSpaceFlightAssist($player);
        $this->removeSpaceCarrier($player);
        $this->dimensionTransitions[$name]["virtualStarted"] = true;
        $this->dimensionTransitions[$name]["virtualTicks"] = 0;
        $this->dimensionTransitions[$name]["virtualHold"] = [(float) $player->x, (float) $player->y, (float) $player->z];
        $this->dimensionTransitions[$name]["virtualSourceWorld"] = strtolower($player->getLevel()->getFolderName());

        // Revoke the plugin's temporary survival flight before the synthetic
        // dimension reset starts. Unlike a real switchLevel(), this is not racing
        // against server-side level mutation, so AdventureSettings can be sent in
        // a deterministic order.
        if(!empty($this->dimensionTransitions[$name]["revokeFlightInNether"]) && !$player->isCreative()){
            $this->disablePlayerFlight($player, false);
        }
        $player->setMotion(new Vector3(0, 0, 0));

        $this->virtualRelayPacketBypass[$name] = true;
        try{
            $pk = new ChangeDimensionPacket();
            $pk->dimension = ChangeDimensionPacket::DIMENSION_NETHER;
            $player->dataPacket($pk);

            $radius = max(1, min(2, (int) $this->getConfig()->getNested("dimension-transfer.virtual-fake-radius", 1)));
            $radiusPk = new ChunkRadiusUpdatePacket();
            $radiusPk->radius = $radius;
            $player->dataPacket($radiusPk);

            // Use the packet coordinate conventions used by Player::sendPosition:
            // Y is eye position, not feet position. The fake scene itself is only
            // client-side; no server block or level is touched.
            $this->sendMovementResetAt($player, 0.5, 64.0, 0.5, true);

            $coords = [];
            for($x = -$radius; $x <= $radius; ++$x){
                for($z = -$radius; $z <= $radius; ++$z){
                    $coords[] = [$x, $z, abs($x) + abs($z)];
                }
            }
            $this->sendEmptyChunks($player, $coords);

            // Keep PLAYER_SPAWN behind the fake FullChunkData packets in the
            // same end-of-tick batch. Sending status immediately while the nine
            // chunks are merely queued can make 0.14 finish the loading screen
            // before it has accepted the synthetic scene.
            $spawn = new PlayStatusPacket();
            $spawn->status = PlayStatusPacket::PLAYER_SPAWN;
            $player->batchDataPacket($spawn);
        }finally{
            unset($this->virtualRelayPacketBypass[$name]);
        }

        $player->sendMessage(TextFormat::DARK_RED . "正在进行虚拟 Nether 缓存刷新……");
        return true;
    }

    private function tickVirtualDimensionTransition(Player $player, $name){
        if(!isset($this->dimensionTransitions[$name])){ return; }
        $transition = $this->dimensionTransitions[$name];
        if(empty($transition["virtualStarted"])){
            if(!$this->beginVirtualDimensionRelay($player, $name)){ return; }
            $transition = $this->dimensionTransitions[$name];
        }

        $sourceWorld = strtolower((string) ($transition["virtualSourceWorld"] ?? ""));
        if($sourceWorld !== "" && strtolower($player->getLevel()->getFolderName()) !== $sourceWorld){
            $this->getLogger()->warning("Virtual dimension relay source world changed unexpectedly for " . $player->getName() . "; cancelling relay.");
            $this->cancelDimensionTransition($name);
            return;
        }

        // Incoming movement packets are cancelled while this relay is active, but
        // keep the authoritative server entity pinned as a second line of defence.
        $hold = (array) ($transition["virtualHold"] ?? [$player->x, $player->y, $player->z]);
        if(count($hold) >= 3){
            $player->setPosition(new Vector3((float) $hold[0], (float) $hold[1], (float) $hold[2]));
        }
        if((((int) ($transition["virtualTicks"] ?? 0)) % 5) === 0){
            $player->setMotion(new Vector3(0, 0, 0));
        }

        ++$this->dimensionTransitions[$name]["virtualTicks"];
        $ticks = (int) $this->dimensionTransitions[$name]["virtualTicks"];

        // Reinforce both pieces of state while the client is safely inside the
        // synthetic dimension: allow_fly=false and an authoritative movement
        // MODE_RESET. No fake game-mode packet is involved.
        if(!empty($transition["revokeFlightInNether"]) && !$player->isCreative()){
            if($ticks === 10 || $ticks === 30){
                $this->disablePlayerFlight($player, false);
                $this->virtualRelayPacketBypass[$name] = true;
                try{
                    $this->sendMovementResetAt($player, 0.5, 64.0, 0.5, true);
                }finally{
                    unset($this->virtualRelayPacketBypass[$name]);
                }
            }
        }

        $wait = max(20, min(160, (int) $this->getConfig()->getNested("dimension-transfer.virtual-hold-ticks", 60)));
        if($ticks < $wait){
            return;
        }

        if(!empty($transition["revokeFlightInNether"]) && !$player->isCreative()){
            $this->disablePlayerFlight($player, false);
        }
        $this->finishDimensionTransition($player);
    }

    /** Send the same authoritative movement reset packet used by Genisys teleport. */
    private function sendMovementResetAt(Player $player, $x, $feetY, $z, $onGround){
        $pk = new MovePlayerPacket();
        $pk->eid = 0;
        $pk->x = (float) $x;
        $pk->y = (float) $feetY + (float) $player->getEyeHeight();
        $pk->z = (float) $z;
        $pk->bodyYaw = (float) $player->yaw;
        $pk->pitch = (float) $player->pitch;
        $pk->yaw = (float) $player->yaw;
        $pk->mode = MovePlayerPacket::MODE_RESET;
        $pk->onGround = (bool) $onGround;
        $player->dataPacket($pk);
    }

    private function isPlayerApproximatelyGrounded(Player $player){
        $level = $player->getLevel();
        if($level === null){ return false; }
        $x = (int) floor($player->x);
        $z = (int) floor($player->z);
        $y = max(0, min(127, (int) floor($player->y - 0.05)));
        return $this->isSolidForLanding($level->getBlockIdAt($x, $y, $z));
    }

    private function sendClientFlightPostureReset(Player $player, $sendSpawnStatus = false, $forceGrounded = null){
        if(!(bool) $this->getConfig()->getNested("dimension-transfer.client-reset-after-flight-revoke", true)){
            return;
        }
        $onGround = $forceGrounded === null ? $this->isPlayerApproximatelyGrounded($player) : (bool) $forceGrounded;
        $this->sendMovementResetAt($player, $player->x, $player->y, $player->z, $onGround);
        if($sendSpawnStatus){
            $pk = new PlayStatusPacket();
            $pk->status = PlayStatusPacket::PLAYER_SPAWN;
            $player->dataPacket($pk);
        }
    }

    /**
     * Return the Nether dimension ID used by the running server core.
     *
     * Bedrock/Genisys cores commonly use 0=Overworld, 1=Nether, 2=End.
     * Some old forks expose a different value, so never hard-code Java's -1.
     */
    private function getCoreNetherDimension(){
        $constant = Level::class . "::DIMENSION_NETHER";
        if(defined($constant)){
            return (int) constant($constant);
        }
        // API 2.0-era Genisys uses 1. This fallback is only used on forks
        // which provide getDimension() but do not expose DIMENSION_NETHER.
        return 1;
    }

    private function getNetherLevel(){
        $server = $this->getServer();
        $world = trim((string) $this->getConfig()->getNested("dimension-transfer.nether-world", "nether"));
        if($world === ""){ $world = "nether"; }
        $nether = $server->getLevelByName($world);
        if($nether === null && $server->isLevelGenerated($world)){
            $server->loadLevel($world);
            $nether = $server->getLevelByName($world);
        }
        if($nether !== null && method_exists($nether, "getDimension")){
            $actual = (int) $nether->getDimension();
            $expected = $this->getCoreNetherDimension();
            if($actual !== $expected){
                $this->getLogger()->warning("Configured Nether transfer world '" . $world . "' reports dimension " . $actual . "; this core defines Nether as " . $expected . ".");
                return null;
            }
        }
        return $nether;
    }

    private function isEarthWorldName($world){
        return strtolower((string) $world) === strtolower((string) $this->getConfig()->getNested("earth.return-world", "earth"));
    }

    private function persistDimensionTarget($name, Position $target){
        if(!($this->dimensionTransitionStore instanceof Config) || $target->getLevel() === null){
            return;
        }
        $record = [
            "world" => $target->getLevel()->getFolderName(),
            "x" => (float) $target->x,
            "y" => (float) $target->y,
            "z" => (float) $target->z
        ];
        if(isset($this->dimensionTransitions[$name]["originalGamemode"])){
            $record["originalGamemode"] = (int) $this->dimensionTransitions[$name]["originalGamemode"];
        }
        foreach(["sourceWorld", "sourceX", "sourceY", "sourceZ", "sourceYaw", "sourcePitch"] as $sourceKey){
            if(isset($this->dimensionTransitions[$name][$sourceKey])){
                $record[$sourceKey] = $this->dimensionTransitions[$name][$sourceKey];
            }
        }
        $this->dimensionTransitionStore->set($name, $record);
        $this->dimensionTransitionStore->save();
    }

    private function getNetherRelayPosition(){
        return [self::NETHER_RELAY_X, self::NETHER_RELAY_Y, self::NETHER_RELAY_Z];
    }

    private function prepareNetherTransferPad($nether){
        $pos = $this->getNetherRelayPosition();
        $cx = (int) floor($pos[0]);
        $feetY = max(8, min(116, (int) floor($pos[1])));
        $cz = (int) floor($pos[2]);

        // 1.0.12 keeps a deliberately high, open-air relay cell. The old Y=117
        // pad only cleared four blocks above the player, so naturally generated
        // Nether ceiling material could overlap the reset fall path. A 15x15
        // obsidian deck at feetY-1 plus a full air shaft to Y=126 guarantees the
        // client can visibly fall onto solid ground before the second dimension hop.
        $radius = 7;
        $minChunkX = ($cx - $radius) >> 4;
        $maxChunkX = ($cx + $radius) >> 4;
        $minChunkZ = ($cz - $radius) >> 4;
        $maxChunkZ = ($cz + $radius) >> 4;
        for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
            for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
                $nether->loadChunk($chunkX, $chunkZ, true);
                $nether->populateChunk($chunkX, $chunkZ, true);
            }
        }

        for($x = $cx - $radius; $x <= $cx + $radius; ++$x){
            for($z = $cz - $radius; $z <= $cz + $radius; ++$z){
                $nether->setBlock(new Vector3($x, $feetY - 1, $z), Block::get(Block::OBSIDIAN), true, false);
                for($y = $feetY; $y <= 126; ++$y){
                    $nether->setBlock(new Vector3($x, $y, $z), Block::get(Block::AIR), true, false);
                }
            }
        }
    }

    private function attemptNetherTransfer(Player $player, $nether){
        $name = strtolower($player->getName());
        if(!isset($this->dimensionTransitions[$name])){ return false; }
        $pos = $this->getNetherRelayPosition();
        $this->dimensionTransitions[$name]["retryAt"] = microtime(true) + 0.75;
        $this->dimensionTransitions[$name]["netherAttempted"] = true;
        if((float) ($this->dimensionTransitions[$name]["netherStarted"] ?? 0.0) <= 0.0){
            $this->dimensionTransitions[$name]["netherStarted"] = microtime(true);
        }
        $this->dimensionTransitions[$name]["enteredNether"] = false;
        $this->dimensionTransitions[$name]["netherEnteredAt"] = 0.0;
        $this->dimensionTransitions[$name]["netherTerrainReady"] = false;
        $this->dimensionTransitions[$name]["netherChunks"] = [];
        $this->dimensionTransitions[$name]["netherWaitNotice"] = false;
        $this->dimensionTransitions[$name]["serverReady"] = false;
        $this->dimensionTransitions[$name]["netherTicks"] = 0;
        $this->dimensionTransitions[$name]["netherClientSettleTicks"] = 0;
        $this->dimensionTransitions[$name]["netherClientResyncPasses"] = 0;
        $spawnHeight = max(3, min(10, (int) $this->getConfig()->getNested("dimension-transfer.reset-spawn-height", 6)));
        $spawnY = min(125.0, (float) floor($pos[1]) + (float) $spawnHeight);
        $ok = $player->teleport(new Position((float) $pos[0] + 0.5, $spawnY, (float) $pos[2] + 0.5, $nether));
        if($ok){
            $player->setMotion(new Vector3(0, 0, 0));
        }else{
            $this->getLogger()->debug("Nether transfer attempt was refused for " . $player->getName() . "; retrying after target is already prepared.");
        }
        return $ok;
    }

    private function restoreTransitionGamemodeIfNeeded(Player $player, $name){
        if(!isset($this->dimensionTransitions[$name])){ return; }
        $transition = $this->dimensionTransitions[$name];
        $snapshot = $transition["nativeInventorySnapshot"] ?? null;
        $originalGamemode = isset($transition["originalGamemode"]) ? (int) $transition["originalGamemode"] : null;
        if($originalGamemode !== null && $player->getGamemode() !== $originalGamemode && is_array($snapshot)){
            $this->setGamemodePreservingInventory($player, $originalGamemode, $snapshot);
        }elseif(is_array($snapshot) && !$this->playerInventoryMatchesSnapshot($player, $snapshot)){
            $this->restorePlayerInventorySnapshot($player, $snapshot);
        }
        if($originalGamemode !== null && (($originalGamemode & 0x01) === 0)){
            $this->disablePlayerFlight($player, false);
        }
    }

    /** Snapshot every survival inventory/armor slot plus hotbar mapping. */
    private function snapshotPlayerInventory(Player $player){
        $inventory = $player->getInventory();
        $slots = [];
        $limit = (int) $inventory->getSize() + 4;
        for($i = 0; $i < $limit; ++$i){
            $slots[$i] = clone $inventory->getItem($i);
        }
        $hotbar = [];
        for($i = 0; $i < $inventory->getHotbarSize(); ++$i){
            $hotbar[$i] = (int) $inventory->getHotbarSlotIndex($i);
        }
        return [
            "slots" => $slots,
            "hotbar" => $hotbar,
            "held" => (int) $inventory->getHeldItemIndex()
        ];
    }

    private function playerInventoryMatchesSnapshot(Player $player, array $snapshot){
        $inventory = $player->getInventory();
        $slots = (array) ($snapshot["slots"] ?? []);
        $limit = (int) $inventory->getSize() + 4;
        for($i = 0; $i < $limit; ++$i){
            $expected = isset($slots[$i]) && $slots[$i] instanceof Item ? $slots[$i] : Item::get(Item::AIR, 0, 0);
            $actual = $inventory->getItem($i);
            if($actual->getCount() !== $expected->getCount() || !$actual->equals($expected, true, true)){
                return false;
            }
        }
        $hotbar = (array) ($snapshot["hotbar"] ?? []);
        for($i = 0; $i < $inventory->getHotbarSize(); ++$i){
            if(isset($hotbar[$i]) && (int) $inventory->getHotbarSlotIndex($i) !== (int) $hotbar[$i]){
                return false;
            }
        }
        return (int) $inventory->getHeldItemIndex() === (int) ($snapshot["held"] ?? 0);
    }

    private function restorePlayerInventorySnapshot(Player $player, array $snapshot){
        $inventory = $player->getInventory();
        $slots = (array) ($snapshot["slots"] ?? []);
        $limit = (int) $inventory->getSize() + 4;
        for($i = 0; $i < $limit; ++$i){
            $item = isset($slots[$i]) && $slots[$i] instanceof Item ? clone $slots[$i] : Item::get(Item::AIR, 0, 0);
            $inventory->setItem($i, $item);
        }
        $hotbar = (array) ($snapshot["hotbar"] ?? []);
        for($i = 0; $i < $inventory->getHotbarSize(); ++$i){
            if(isset($hotbar[$i])){
                $inventory->setHotbarSlotIndex($i, (int) $hotbar[$i]);
            }
        }
        $inventory->setHeldItemIndex(max(0, min($inventory->getHotbarSize() - 1, (int) ($snapshot["held"] ?? 0))));
        $inventory->sendContents($player);
        $inventory->sendArmorContents($player);
        $inventory->sendHeldItem($player);
    }

    /**
     * Use the core's real gamemode state machine without allowing Genisys'
     * player.auto-clear-inventory option to destroy inventory. We also keep a
     * complete slot snapshot and verify it after every pulse, so even a fork
     * which ignores autoClearInv cannot lose player items.
     */
    private function setGamemodePreservingInventory(Player $player, $gamemode, array $snapshot){
        $server = $player->getServer();
        $hadAutoClear = property_exists($server, "autoClearInv");
        $oldAutoClear = $hadAutoClear ? (bool) $server->autoClearInv : null;
        if($hadAutoClear){
            $server->autoClearInv = false;
        }
        try{
            $ok = $player->getGamemode() === (int) $gamemode ? true : (bool) $player->setGamemode((int) $gamemode);
        }finally{
            if($hadAutoClear){
                $server->autoClearInv = $oldAutoClear;
            }
        }
        if(!$this->playerInventoryMatchesSnapshot($player, $snapshot)){
            $this->getLogger()->warning("Gamemode reset touched inventory for " . $player->getName() . "; restoring exact pre-reset snapshot.");
            $this->restorePlayerInventorySnapshot($player, $snapshot);
        }else{
            // setGamemode() already resends contents, but explicitly refresh armor
            // and held item too so the 0.14 client cannot retain a creative ghost slot.
            $player->getInventory()->sendArmorContents($player);
            $player->getInventory()->sendHeldItem($player);
        }
        return $ok;
    }

    /** Re-send the already-real Nether scene after Genisys has queued it. */
    private function forceNetherClientSceneSync(Player $player, $sendSpawnStatus = true){
        $netherName = strtolower((string) $this->getConfig()->getNested("dimension-transfer.nether-world", "nether"));
        if(strtolower($player->getLevel()->getFolderName()) !== $netherName){
            return false;
        }
        $pos = $this->getNetherRelayPosition();
        $centerX = ((int) floor($pos[0])) >> 4;
        $centerZ = ((int) floor($pos[2])) >> 4;
        $coords = [];
        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                $coords[] = [$centerX + $dx, $centerZ + $dz];
            }
        }
        $radiusPk = new ChunkRadiusUpdatePacket();
        $radiusPk->radius = 3;
        $player->dataPacket($radiusPk);
        $this->forceChunkRefreshCoords($player, $coords);
        $this->sendMovementResetAt($player, $player->x, $player->y, $player->z, $this->isPlayerApproximatelyGrounded($player));
        if($sendSpawnStatus){
            $pk = new PlayStatusPacket();
            $pk->status = PlayStatusPacket::PLAYER_SPAWN;
            $player->dataPacket($pk);
        }
        return true;
    }

    /**
     * A failed relay must never strand a player in Nether. The source is stored
     * before the first hop; Nether -> source is a genuine dimension 1 -> 0 switch,
     * so the core can safely rebuild the client scene. Universe flight is then
     * restored normally.
     */
    private function rescueFailedDimensionTransition(Player $player, $name, $reason = "unknown"){
        if(!isset($this->dimensionTransitions[$name])){ return false; }
        $transition = $this->dimensionTransitions[$name];
        $this->getLogger()->warning("Dimension relay rescue for " . $player->getName() . ": " . $reason);
        $this->restoreTransitionGamemodeIfNeeded($player, $name);

        $sourceWorld = (string) ($transition["sourceWorld"] ?? $this->universeName);
        $this->getServer()->loadLevel($sourceWorld);
        $sourceLevel = $this->getServer()->getLevelByName($sourceWorld);
        if($sourceLevel === null){
            $sourceWorld = $this->universeName;
            $this->getServer()->loadLevel($sourceWorld);
            $sourceLevel = $this->getServer()->getLevelByName($sourceWorld);
        }
        if($sourceLevel === null){
            $this->cancelDimensionTransition($name);
            return false;
        }

        if(isset($transition["sourceX"], $transition["sourceY"], $transition["sourceZ"])){
            $target = new Position((float) $transition["sourceX"], (float) $transition["sourceY"], (float) $transition["sourceZ"], $sourceLevel);
            $yaw = (float) ($transition["sourceYaw"] ?? $player->yaw);
            $pitch = (float) ($transition["sourcePitch"] ?? $player->pitch);
        }elseif(strtolower($sourceWorld) === strtolower($this->universeName)){
            $entry = $this->getUniverseEarthEntry();
            $target = new Position((float) $entry[0], (float) $entry[1], (float) $entry[2], $sourceLevel);
            $yaw = $player->yaw;
            $pitch = $player->pitch;
        }else{
            $safe = $sourceLevel->getSafeSpawn();
            $target = new Position((float) $safe->x, (float) $safe->y, (float) $safe->z, $sourceLevel);
            $yaw = $player->yaw;
            $pitch = $player->pitch;
        }

        $ok = $player->teleport($target, $yaw, $pitch);
        $this->cancelDimensionTransition($name);
        if($ok){
            $this->setCooldown($name);
            $this->queueWorldResync($player);
            if(strtolower($sourceLevel->getFolderName()) === strtolower($this->universeName)){
                $this->applyUniverseRules($player, 0);
                $this->syncUniverseTime($player);
            }
            $player->sendMessage(TextFormat::YELLOW . "维度中转失败，已安全返回起点；请稍后重新尝试。 ");
        }
        return $ok;
    }

    /**
     * Native-flight exit handshake for survival/adventure players.
     *
     * 1) real Nether chunks are already on the client;
     * 2) pulse real Creative for a few ticks, then restore the original mode;
     * 3) allow normal gravity to drop the player onto the obsidian deck;
     * 4) require several consecutive server-side onGround ticks;
     * 5) send one final grounded MODE_RESET, then permit the destination hop.
     *
     * This deliberately never guesses that allow_fly=false was enough. If the
     * client fails to become grounded, the second hop is withheld instead of
     * carrying a half-flight posture into Earth/planet.
     */
    private function tickNativeFlightResetInNether(Player $player, $name){
        if(!isset($this->dimensionTransitions[$name])){ return false; }
        if(empty($this->dimensionTransitions[$name]["revokeFlightInNether"])){
            $this->dimensionTransitions[$name]["nativeResetComplete"] = true;
            return true;
        }
        if(!(bool) $this->getConfig()->getNested("dimension-transfer.native-flight-reset", true)){
            $this->disablePlayerFlight($player, false);
            $this->dimensionTransitions[$name]["nativeResetComplete"] = true;
            return true;
        }
        if(!empty($this->dimensionTransitions[$name]["nativeResetComplete"])){
            return true;
        }

        $stage = (string) ($this->dimensionTransitions[$name]["nativeResetStage"] ?? "waiting");
        ++$this->dimensionTransitions[$name]["nativeResetTicks"];
        $stageTicks = (int) $this->dimensionTransitions[$name]["nativeResetTicks"];
        $pos = $this->getNetherRelayPosition();
        $spawnHeight = max(3, min(10, (int) $this->getConfig()->getNested("dimension-transfer.reset-spawn-height", 6)));
        $spawnY = min(125.0, (float) floor($pos[1]) + (float) $spawnHeight);
        $groundY = (float) floor($pos[1]) + 0.01;
        $pulseDelay = max(2, min(10, (int) $this->getConfig()->getNested("dimension-transfer.reset-pulse-delay-ticks", 4)));
        $groundConfirm = max(2, min(20, (int) $this->getConfig()->getNested("dimension-transfer.ground-confirm-ticks", 6)));
        $groundTimeout = max(60, min(400, (int) $this->getConfig()->getNested("dimension-transfer.ground-timeout-ticks", 160)));

        if(method_exists($player, "resetFallDistance")){
            $player->resetFallDistance();
        }else{
            $player->fallDistance = 0;
        }

        if($stage === "waiting"){
            if(!isset($this->dimensionTransitions[$name]["nativeInventorySnapshot"]) || !is_array($this->dimensionTransitions[$name]["nativeInventorySnapshot"])){
                $this->dimensionTransitions[$name]["nativeInventorySnapshot"] = $this->snapshotPlayerInventory($player);
            }
            $snapshot = $this->dimensionTransitions[$name]["nativeInventorySnapshot"];
            // originalGamemode is captured before entering Nether. Never overwrite it
            // during retries, because a retry may begin while a temporary Creative
            // pulse is still being unwound.
            $this->dimensionTransitions[$name]["nativeResetAttempts"] = (int) ($this->dimensionTransitions[$name]["nativeResetAttempts"] ?? 0) + 1;
            $player->teleport(new Position((float) $pos[0] + 0.5, $spawnY, (float) $pos[2] + 0.5, $player->getLevel()));
            $player->setMotion(new Vector3(0, 0, 0));
            if(!$this->setGamemodePreservingInventory($player, Player::CREATIVE, $snapshot)){
                $player->sendMessage(TextFormat::RED . "飞行态复位被其他插件阻止；正在安全返回宇宙，而不是把你留在 Nether。 ");
                $this->rescueFailedDimensionTransition($player, $name, "gamemode-pulse-refused");
                return false;
            }
            $this->dimensionTransitions[$name]["nativeResetStage"] = "creative";
            $this->dimensionTransitions[$name]["nativeResetTicks"] = 0;
            $player->sendMessage(TextFormat::DARK_RED . "正在安全退出宇宙飞行态……");
            return false;
        }

        if($stage === "creative"){
            $player->setMotion(new Vector3(0, 0, 0));
            if($stageTicks < $pulseDelay){
                return false;
            }
            $snapshot = $this->dimensionTransitions[$name]["nativeInventorySnapshot"];
            if(!is_array($snapshot)){
                $snapshot = $this->snapshotPlayerInventory($player);
                $this->dimensionTransitions[$name]["nativeInventorySnapshot"] = $snapshot;
            }
            $originalGamemode = (int) ($this->dimensionTransitions[$name]["originalGamemode"] ?? Player::SURVIVAL);
            if($originalGamemode === Player::CREATIVE || $originalGamemode === Player::SPECTATOR){
                $originalGamemode = Player::SURVIVAL;
            }
            if(!$this->setGamemodePreservingInventory($player, $originalGamemode, $snapshot)){
                $this->restorePlayerInventorySnapshot($player, $snapshot);
                $player->sendMessage(TextFormat::RED . "无法恢复原游戏模式；正在安全返回宇宙。 ");
                $this->rescueFailedDimensionTransition($player, $name, "gamemode-restore-refused");
                return false;
            }

            // Do not wait for client-authoritative natural falling here. On MCPE 0.14
            // a dimension switch can be visually stuck on the red Nether scene even
            // though Genisys has already queued the chunks; in that state no useful
            // falling packets arrive and the old code timed out forever. Instead,
            // complete the real gamemode pulse first, then put the player's FEET on
            // the known obsidian deck and send one authoritative grounded reset.
            $this->disablePlayerFlight($player, false);
            $ground = new Position((float) $pos[0] + 0.5, $groundY, (float) $pos[2] + 0.5, $player->getLevel());
            $player->teleport($ground, $player->yaw, $player->pitch);
            $player->setMotion(new Vector3(0, 0, 0));
            $this->sendClientFlightPostureReset($player, true, true);
            $this->dimensionTransitions[$name]["nativeResetStage"] = "grounding";
            $this->dimensionTransitions[$name]["nativeResetTicks"] = 0;
            $this->dimensionTransitions[$name]["nativeGroundTicks"] = 0;
            return false;
        }

        if($stage === "grounding"){
            $originalGamemode = (int) ($this->dimensionTransitions[$name]["originalGamemode"] ?? Player::SURVIVAL);
            $nearDeck = abs((float) $player->x - ((float) $pos[0] + 0.5)) <= 1.25
                && abs((float) $player->z - ((float) $pos[2] + 0.5)) <= 1.25
                && abs((float) $player->y - $groundY) <= 0.85;
            $grounded = $nearDeck
                && $player->getGamemode() === $originalGamemode
                && !$player->getAllowFlight()
                && $this->isPlayerApproximatelyGrounded($player);

            if($grounded){
                ++$this->dimensionTransitions[$name]["nativeGroundTicks"];
                if((int) $this->dimensionTransitions[$name]["nativeGroundTicks"] >= $groundConfirm){
                    $snapshot = $this->dimensionTransitions[$name]["nativeInventorySnapshot"];
                    if(is_array($snapshot) && !$this->playerInventoryMatchesSnapshot($player, $snapshot)){
                        $this->restorePlayerInventorySnapshot($player, $snapshot);
                    }
                    $player->setMotion(new Vector3(0, 0, 0));
                    $this->disablePlayerFlight($player, false);
                    $this->sendClientFlightPostureReset($player, true, true);
                    $this->dimensionTransitions[$name]["relayFlightRevoked"] = true;
                    $this->dimensionTransitions[$name]["relayClientResetSent"] = true;
                    $this->dimensionTransitions[$name]["nativeResetComplete"] = true;
                    $this->dimensionTransitions[$name]["nativeResetStage"] = "grounded";
                    $this->dimensionTransitions[$name]["nativeResetTicks"] = 0;
                    $player->sendMessage(TextFormat::GREEN . "已在黑曜石平台确认落地并退出飞行态，准备前往目标世界……");
                    return true;
                }
            }else{
                $this->dimensionTransitions[$name]["nativeGroundTicks"] = 0;
            }

            // One gentle re-anchor, not a per-tick correction. This also repairs
            // the red-screen case by making the target position and grounded flag
            // explicit after the client has had time to process the Nether chunks.
            if($stageTicks === 20){
                $this->forceNetherClientSceneSync($player, true);
                $ground = new Position((float) $pos[0] + 0.5, $groundY, (float) $pos[2] + 0.5, $player->getLevel());
                $player->teleport($ground, $player->yaw, $player->pitch);
                $this->sendClientFlightPostureReset($player, true, true);
            }

            if($stageTicks >= $groundTimeout){
                $attempts = (int) ($this->dimensionTransitions[$name]["nativeResetAttempts"] ?? 1);
                if($attempts < 2){
                    $this->forceNetherClientSceneSync($player, true);
                    $this->dimensionTransitions[$name]["nativeResetStage"] = "waiting";
                    $this->dimensionTransitions[$name]["nativeResetTicks"] = 0;
                    $this->dimensionTransitions[$name]["nativeGroundTicks"] = 0;
                    $player->sendMessage(TextFormat::YELLOW . "Nether 客户端场景未稳定，已重发中转区块并重新执行一次飞行态复位……");
                    return false;
                }
                $player->sendMessage(TextFormat::RED . "Nether 中转客户端状态仍未稳定；不会把你困在红色虚空，正在安全返回宇宙。 ");
                $this->rescueFailedDimensionTransition($player, $name, "ground-confirm-timeout");
                return false;
            }
            return false;
        }

        return !empty($this->dimensionTransitions[$name]["nativeResetComplete"]);
    }

    /**
     * Wait until the player is actually server-side in Nether before counting
     * any settling time.  If the first teleport is refused while another old
     * teleport is still resolving, retry it instead of silently skipping the
     * cache-flush hop and eventually dumping the player into the destination.
     */
    private function tickDimensionTransitions(){
        $now = microtime(true);
        $netherName = strtolower((string) $this->getConfig()->getNested("dimension-transfer.nether-world", "nether"));
        foreach($this->dimensionTransitions as $name => $transition){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player)){ continue; }

            // Stage 1: destination terrain must be completely ready before the
            // client cache-flush phase starts, regardless of relay type.
            if(empty($transition["targetPrepared"])){
                if($now - (float) $transition["started"] >= 30.0){
                    $player->sendMessage(TextFormat::RED . "目标世界生成超时，本次传送已取消。");
                    $this->cancelDimensionTransition($name);
                    continue;
                }
                if(!$this->prepareDimensionTarget($name)){
                    continue;
                }
                $transition = $this->dimensionTransitions[$name];
            }

            $relayMode = (string) ($this->dimensionTransitions[$name]["relayMode"] ?? "real");
            if($relayMode === "virtual"){
                $this->tickVirtualDimensionTransition($player, $name);
                continue;
            }

            // Real-Nether relay path used for planet travel and other normal
            // cross-world transfers.
            if((float) ($this->dimensionTransitions[$name]["netherStarted"] ?? 0.0) <= 0.0){
                $this->dimensionTransitions[$name]["netherStarted"] = $now;
                $transition = $this->dimensionTransitions[$name];
            }

            $currentWorld = strtolower($player->getLevel()->getFolderName());
            if($currentWorld !== $netherName){
                $netherStarted = (float) ($transition["netherStarted"] ?? 0.0);
                if($netherStarted <= 0.0){
                    $netherStarted = $now;
                    $this->dimensionTransitions[$name]["netherStarted"] = $now;
                }
                if($now - $netherStarted >= 30.0){
                    $this->getLogger()->warning("Nether cache-flush transfer failed for " . $player->getName() . "; destination teleport cancelled.");
                    $player->sendMessage(TextFormat::RED . "Nether 中转失败，本次目标传送已取消。§7不会直接跳过中转。");
                    $this->cancelDimensionTransition($name);
                    continue;
                }
                if($now >= (float) ($transition["retryAt"] ?? 0.0)){
                    $nether = $this->getNetherLevel();
                    if($nether !== null){
                        $this->prepareNetherTransferPad($nether);
                        $this->attemptNetherTransfer($player, $nether);
                    }
                }
                continue;
            }

            if(empty($transition["enteredNether"])){
                $this->dimensionTransitions[$name]["enteredNether"] = true;
                $this->dimensionTransitions[$name]["netherEnteredAt"] = $now;
                $this->dimensionTransitions[$name]["netherTicks"] = 0;
                $transition = $this->dimensionTransitions[$name];
                $player->sendMessage(TextFormat::DARK_RED . "已进入 Nether；正在等待中转区块真正发送到客户端……");
            }

            if(empty($this->dimensionTransitions[$name]["netherTerrainReady"])){
                $ready = $this->areNetherTransferChunksSent($player, $this->dimensionTransitions[$name]);
                if($ready){
                    $this->dimensionTransitions[$name]["netherTerrainReady"] = true;
                    $this->dimensionTransitions[$name]["netherTicks"] = 0;
                    $this->dimensionTransitions[$name]["netherClientSettleTicks"] = 0;
                    $this->dimensionTransitions[$name]["netherClientResyncPasses"] = 0;
                    // "queued/sent" is not the same as "MCPE 0.14 has finished
                    // constructing the new dimension". Re-send the real relay scene
                    // once and give the client a quiet settle window before touching
                    // gamemode/flight state. This prevents the red-void race seen
                    // during rapid Universe -> Earth cycles.
                    $this->forceNetherClientSceneSync($player, true);
                    $player->sendMessage(TextFormat::DARK_RED . "Nether 地形已发送；正在等待客户端稳定显示中转世界……");
                    $this->getLogger()->debug("Nether 3x3 transfer chunks are sent for " . $player->getName());
                }else{
                    $player->setMotion(new Vector3(0, 0, 0));
                    $enteredAt = (float) ($this->dimensionTransitions[$name]["netherEnteredAt"] ?? $now);
                    if(($now - $enteredAt) >= 15.0 && empty($this->dimensionTransitions[$name]["netherWaitNotice"])){
                        $this->dimensionTransitions[$name]["netherWaitNotice"] = true;
                        $player->sendMessage(TextFormat::YELLOW . "Nether 区块仍未全部发完，继续停在中转台等待；不会冒险直接二跳。");
                    }
                    continue;
                }
            }

            ++$this->dimensionTransitions[$name]["netherClientSettleTicks"];
            $settleTicks = (int) $this->dimensionTransitions[$name]["netherClientSettleTicks"];
            $settleRequired = max(20, min(120, (int) $this->getConfig()->getNested("dimension-transfer.nether-client-settle-ticks", 40)));
            $resyncInterval = max(10, min(60, (int) $this->getConfig()->getNested("dimension-transfer.nether-resync-interval-ticks", 20)));
            if($settleTicks < $settleRequired){
                if(($settleTicks % $resyncInterval) === 0){
                    $this->forceNetherClientSceneSync($player, true);
                    ++$this->dimensionTransitions[$name]["netherClientResyncPasses"];
                }
                continue;
            }

            ++$this->dimensionTransitions[$name]["netherTicks"];
            $netherTicks = (int) $this->dimensionTransitions[$name]["netherTicks"];
            $earthTarget = !empty($transition["earthTarget"]);
            $hold = max(40, (int) $this->getConfig()->getNested(
                $earthTarget ? "dimension-transfer.earth-hold-ticks" : "dimension-transfer.nether-hold-ticks",
                $earthTarget ? 120 : 100
            ));

            $needsNativeReset = !empty($this->dimensionTransitions[$name]["revokeFlightInNether"])
                && (bool) $this->getConfig()->getNested("dimension-transfer.native-flight-reset", true);

            if($needsNativeReset){
                // Do not continuously zero Motion here. Once the real gamemode
                // pulse returns the player to survival/adventure, natural falling
                // onto the obsidian deck is the proof that client flight ended.
                $resetReady = $this->tickNativeFlightResetInNether($player, $name);
                if(!isset($this->dimensionTransitions[$name])){
                    continue;
                }
                if(!$resetReady){
                    continue;
                }
            }else{
                if(!empty($this->dimensionTransitions[$name]["revokeFlightInNether"])){
                    $this->disablePlayerFlight($player, false);
                }
                if(($netherTicks % 5) === 0){
                    $player->setMotion(new Vector3(0, 0, 0));
                }
            }

            if($netherTicks >= $hold){
                if(!empty($this->dimensionTransitions[$name]["revokeFlightInNether"])){
                    if($needsNativeReset && empty($this->dimensionTransitions[$name]["nativeResetComplete"])){
                        continue;
                    }
                    $this->disablePlayerFlight($player, false);
                    $this->sendClientFlightPostureReset($player, false, true);
                }
                $this->finishDimensionTransition($player);
            }
        }
    }

    /**
     * Confirm the exact 3x3 chunk gate around the Nether wait cell has been
     * marked SENT for this player. We use two independent signals:
     *  1) FullChunkDataPacket observed by DataPacketSendEvent;
     *  2) Genisys Player::$usedChunks (true = sendChunk() has queued it).
     * If reflection is unavailable, packet observation remains sufficient.
     */
    private function areNetherTransferChunksSent(Player $player, array $transition){
        $pos = $this->getNetherRelayPosition();
        $centerX = ((int) floor($pos[0])) >> 4;
        $centerZ = ((int) floor($pos[2])) >> 4;
        $packetChunks = (array) ($transition["netherChunks"] ?? []);
        $packetReady = true;
        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                if(empty($packetChunks[($centerX + $dx) . ":" . ($centerZ + $dz)])){
                    $packetReady = false;
                    break 2;
                }
            }
        }
        if($packetReady){ return true; }

        if($this->usedChunksReflectionFailed){ return false; }
        try{
            if($this->usedChunksProperty === null){
                $class = new \ReflectionClass($player);
                while($class !== false && !$class->hasProperty("usedChunks")){
                    $class = $class->getParentClass();
                }
                if($class === false){
                    $this->usedChunksReflectionFailed = true;
                    return false;
                }
                $this->usedChunksProperty = $class->getProperty("usedChunks");
                $this->usedChunksProperty->setAccessible(true);
            }
            $used = (array) $this->usedChunksProperty->getValue($player);
            for($dx = -1; $dx <= 1; ++$dx){
                for($dz = -1; $dz <= 1; ++$dz){
                    $hash = Level::chunkHash($centerX + $dx, $centerZ + $dz);
                    if(!isset($used[$hash]) || $used[$hash] !== true){
                        return false;
                    }
                }
            }
            return true;
        }catch(\Throwable $e){
            $this->usedChunksReflectionFailed = true;
            $this->getLogger()->warning("Cannot inspect Genisys usedChunks; falling back to outbound FullChunkDataPacket tracking: " . $e->getMessage());
            return false;
        }
    }

    private function prepareDimensionTarget($name){
        if(!isset($this->dimensionTransitions[$name])){ return false; }
        $target = $this->dimensionTransitions[$name]["target"];
        $level = $target->getLevel();
        if($level === null){ return false; }
        $centerX = ((int) floor($target->x)) >> 4;
        $centerZ = ((int) floor($target->z)) >> 4;
        $radius = max(1, min(2, (int) $this->getConfig()->getNested("dimension-transfer.target-preload-radius", 1)));

        // Pre-generate/populate the same 3x3 region which Genisys itself uses
        // as the delayed-teleport gate.  One populated centre chunk is not enough.
        $ready = true;
        for($dx = -$radius; $dx <= $radius; ++$dx){
            for($dz = -$radius; $dz <= $radius; ++$dz){
                $chunkX = $centerX + $dx;
                $chunkZ = $centerZ + $dz;
                $level->loadChunk($chunkX, $chunkZ, true);
                $level->populateChunk($chunkX, $chunkZ, true);
                if(method_exists($level, "isChunkPopulated") && !$level->isChunkPopulated($chunkX, $chunkZ)){
                    $ready = false;
                }
            }
        }
        if(!$ready){ return false; }

        $world = $level->getFolderName();
        if($this->isPlanetWorld($world)){
            $planet = $this->registry->getByWorld($world);
            if($planet === null){ return false; }
            if((int) ($planet["block"]["id"] ?? 0) === Block::WOOL){
                // Existing wool worlds created by <=0.6.2 are repaired before the
                // client ever enters them: only hidden interior wool is replaced
                // with stone, so the visible coloured surface is unchanged while
                // the pathological all-wool chunk density disappears.
                $this->stabilizeWoolPlanetArea($level, $planet, $centerX, $centerZ, $radius, null);
            }
            $safe = $this->findPlanetArrivalPosition($level, $planet, (int) floor($target->x), (int) floor($target->z));
            $this->dimensionTransitions[$name]["target"] = $safe;
            $level->setSpawnLocation(new Vector3($safe->x, $safe->y, $safe->z));
            $this->persistDimensionTarget($name, $safe);
        }
        $this->dimensionTransitions[$name]["targetPrepared"] = true;
        return true;
    }

    private function cancelDimensionTransition($name){
        // A transition can be cancelled by timeout, another plugin, shutdown,
        // or an exception path while the short Creative reset pulse is active.
        // Never discard the transition record before restoring the original mode.
        if(isset($this->dimensionTransitions[$name])){
            $player = $this->getServer()->getPlayerExact($name);
            if($player instanceof Player){
                $this->restoreTransitionGamemodeIfNeeded($player, $name);
            }
        }
        unset($this->dimensionTransitions[$name]);
        if($this->dimensionTransitionStore instanceof Config){
            $this->dimensionTransitionStore->remove($name);
            $this->dimensionTransitionStore->save();
        }
    }

    private function finishDimensionTransition(Player $player){
        $name = strtolower($player->getName());
        if(!isset($this->dimensionTransitions[$name])){ return; }
        if(empty($this->dimensionTransitions[$name]["targetPrepared"]) && !$this->prepareDimensionTarget($name)){
            return;
        }

        $transition = $this->dimensionTransitions[$name];

        // Hard fail-safe: a survival/adventure transition must never leave Nether
        // while the temporary Creative pulse is still active. This guards against
        // future state-machine regressions and third-party event interference.
        if(!empty($transition["revokeFlightInNether"])){
            $originalGamemode = isset($transition["originalGamemode"]) ? (int) $transition["originalGamemode"] : Player::SURVIVAL;
            if($player->getGamemode() !== $originalGamemode){
                $this->restoreTransitionGamemodeIfNeeded($player, $name);
                if($player->getGamemode() !== $originalGamemode){
                    $player->sendMessage(TextFormat::RED . "未能恢复原游戏模式，已停止二跳并留在 Nether 中转台。");
                    return;
                }
                $transition = $this->dimensionTransitions[$name];
            }
            if((bool) $this->getConfig()->getNested("dimension-transfer.native-flight-reset", true)
                && (empty($transition["nativeResetComplete"]) || (string) ($transition["nativeResetStage"] ?? "") !== "grounded")){
                return;
            }
        }

        $target = $transition["target"];
        $earthTarget = !empty($transition["earthTarget"]);
        $relayMode = (string) ($transition["relayMode"] ?? "real");

        // A virtual relay left the server entity in the source dimension-0
        // world while the client is temporarily in dimension 1. Genisys will
        // NOT emit ChangeDimensionPacket for the upcoming dimension-0 ->
        // dimension-0 Level switch, so explicitly return the client first.
        if($relayMode === "virtual"){
            $this->virtualRelayPacketBypass[$name] = true;
            try{
                $pk = new ChangeDimensionPacket();
                $pk->dimension = ChangeDimensionPacket::DIMENSION_NORMAL;
                $player->dataPacket($pk);

                $radiusPk = new ChunkRadiusUpdatePacket();
                $radiusPk->radius = max(2, min(8, (int) $this->getConfig()->getNested("dimension-transfer.virtual-return-radius", 3)));
                $player->dataPacket($radiusPk);

                // Put the camera directly at the prepared real target. The actual
                // server teleport immediately below will then stream the same
                // dimension-0 chunks instead of first showing the old Universe.
                $this->sendMovementResetAt($player, $target->x, $target->y, $target->z, true);
            }finally{
                unset($this->virtualRelayPacketBypass[$name]);
            }
        }

        // Let the target world's real chunk stream pass through immediately.
        // switchLevel() clears usedChunks, so the post-arrival finalizer below can
        // use the same 3x3 gate as Genisys' own delayed teleport completion.
        if($relayMode === "virtual"){
            $this->virtualRelayPacketBypass[$name] = true;
        }
        try{
            $teleported = $player->teleport($target);
        }finally{
            if($relayMode === "virtual"){
                unset($this->virtualRelayPacketBypass[$name]);
            }
        }
        if(!$teleported){ return; }
        $targetWorld = $target->getLevel() !== null ? $target->getLevel()->getFolderName() : "";
        $groundFlightResetDone = !empty($transition["nativeResetComplete"]);
        if($groundFlightResetDone){
            // Consumed by the destination cleanup below. This tells Earth/planet
            // code that flight posture was already proven gone by a real Nether
            // ground contact, so it must not start another speculative reset loop.
            $this->recentGroundFlightResets[$name] = true;
        }

        // The pre-transfer proximity cooldown has expired by the time a real
        // Nether relay finishes. Start a fresh cooldown now so a player cannot
        // immediately trigger another Earth/planet hop while destination chunks
        // and arrival finalizers are still settling on the 0.14 client.
        $this->setCooldown($name);
        $this->cancelDimensionTransition($name);
        $this->queueWorldResync($player);

        // Revoke survival flight only after the destination 3x3 has really been
        // sent. For virtual Earth this also supplies the PLAYER_SPAWN which the
        // normal->normal core switch intentionally omits. For real Nether->planet
        // the core already sends PLAYER_SPAWN, but a final MODE_RESET *after*
        // allow_fly=false closes the client-side half-flight posture reliably.
        $needsFlightReset = !$groundFlightResetDone && !$player->isCreative()
            && ($this->isPlanetWorld($targetWorld) || ($earthTarget && $this->isEarthWorldName($targetWorld)));
        if($needsFlightReset || $relayMode === "virtual"){
            $this->queueClientArrivalFinalizer(
                $player,
                $target,
                $relayMode === "virtual",
                $needsFlightReset
            );
        }

        if($this->isPlanetWorld($targetWorld)){
            $this->queuePlanetLandingGuard($player, $target);
        }

        $world = $player->getLevel()->getFolderName();
        if($world === $this->universeName){
            $this->syncUniverseTime($player);
            $this->applyUniverseRules($player, 0);
            $this->queueEarthChunkStream($player, 12);
            $this->showSpaceWalkTutorial($player);
        }elseif($this->isPlanetWorld($world)){
            $planet = $this->registry->getByWorld($world);
            if($planet !== null){ $this->completePlanetArrival($player, $planet); }
        }else{
            if($earthTarget && $this->isEarthWorldName($world)){
                $restoreFlight = $this->leaveCustomRules($player, true);
                $this->queueEarthFlightPostureReset($player, $restoreFlight);
                // Planet landing already returned the consumed reusable craft,
                // but Earth landing never did. Keep both destinations symmetric.
                $this->giveAircraft($player);
                $this->sendEarthReport($player);
                $player->sendMessage(TextFormat::GREEN . "已返回 " . $world . "，飞行器已返还。");
            }else{
                $this->leaveCustomRules($player);
            }
        }
    }

    /**
     * Finish the client-side half of a cross-world arrival only after Genisys
     * has sent the destination 3x3 chunks. This mirrors Player::checkTeleportPosition()
     * instead of guessing a fixed delay around the dimension loading screen.
     */
    private function queueClientArrivalFinalizer(Player $player, Position $target, $sendSpawnStatus, $revokeFlight){
        if($target->getLevel() === null){ return; }
        $this->clientArrivalFinalizers[strtolower($player->getName())] = [
            "world" => strtolower($target->getLevel()->getFolderName()),
            "x" => (float) $target->x,
            "z" => (float) $target->z,
            "ticks" => 0,
            "sendSpawnStatus" => (bool) $sendSpawnStatus,
            "revokeFlight" => (bool) $revokeFlight
        ];
    }

    private function arePlayerChunksSentAround(Player $player, $centerX, $centerZ, $radius = 1){
        $used = $this->getPlayerUsedChunks($player);
        if($used === null){ return null; }
        $centerX = (int) $centerX;
        $centerZ = (int) $centerZ;
        $radius = max(0, min(2, (int) $radius));
        for($dx = -$radius; $dx <= $radius; ++$dx){
            for($dz = -$radius; $dz <= $radius; ++$dz){
                $hash = Level::chunkHash($centerX + $dx, $centerZ + $dz);
                if(!isset($used[$hash]) || $used[$hash] !== true){
                    return false;
                }
            }
        }
        return true;
    }

    private function tickClientArrivalFinalizers(){
        foreach($this->clientArrivalFinalizers as $name => $state){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player)){
                unset($this->clientArrivalFinalizers[$name]);
                continue;
            }
            if(strtolower($player->getLevel()->getFolderName()) !== $state["world"]){
                ++$this->clientArrivalFinalizers[$name]["ticks"];
                if($this->clientArrivalFinalizers[$name]["ticks"] >= 100){
                    unset($this->clientArrivalFinalizers[$name]);
                }
                continue;
            }

            ++$this->clientArrivalFinalizers[$name]["ticks"];
            $ticks = (int) $this->clientArrivalFinalizers[$name]["ticks"];
            $chunkX = ((int) floor((float) $state["x"])) >> 4;
            $chunkZ = ((int) floor((float) $state["z"])) >> 4;
            $ready = $this->arePlayerChunksSentAround($player, $chunkX, $chunkZ, 1);

            // When reflection is available, wait for the exact same nine chunks
            // as the core teleport gate. If a fork hides usedChunks, fall back to
            // a modest delay; never block the player indefinitely.
            if($ready !== true){
                if($ready === null){
                    if($ticks < 12){ continue; }
                }elseif($ticks < 50){
                    continue;
                }
            }

            if(!empty($state["revokeFlight"]) && !$player->isCreative()){
                $this->clearSpaceFlightAssist($player);
                $this->disablePlayerFlight($player, false);
            }

            // Core has now had a chance to issue its own teleport MODE_RESET.
            // Re-send it *after* allow_fly=false so the 0.14 client cannot retain
            // the stale flying posture from Universe.
            $this->sendClientFlightPostureReset($player, false, null);

            if(!empty($state["sendSpawnStatus"])){
                $pk = new PlayStatusPacket();
                $pk->status = PlayStatusPacket::PLAYER_SPAWN;
                $player->dataPacket($pk);
            }

            // Earth cleanup historically performed an extra same-world teleport.
            // The chunk-gated reset above supersedes that and avoids another
            // correction packet racing the freshly loaded destination.
            if(isset($this->flightPostureRestores[$name]) && !empty($this->flightPostureRestores[$name]["earthReset"])){
                $this->flightPostureRestores[$name]["positionReset"] = true;
                $this->flightPostureRestores[$name]["ticks"] = min(12, (int) $this->flightPostureRestores[$name]["ticks"]);
            }

            unset($this->clientArrivalFinalizers[$name]);
        }
    }

    /**
     * Queue a short destination-state refresh after a completed teleport.
     *
     * Generic world resync only reapplies time/environment state. Universe
     * landmark rendering is repaired separately through real population +
     * heightmap/skylight rebuild; the old air-chunk scrub is permanently off.
     */
    private function queueWorldResync(Player $player){
        $this->worldResyncs[strtolower($player->getName())] = [
            "ticks" => 30,
            "world" => strtolower($player->getLevel()->getFolderName())
        ];
    }

    /**
     * Re-send Earth chunks one at a time instead of dumping a 5x5 burst into
     * the 0.14 renderer. Burst refreshes made the missing column merely flash;
     * a staggered stream gives the old client's mesh worker time to finish each
     * lightweight shell chunk before the next one arrives.
     */
    private function queueEarthChunkStream(Player $player, $delayTicks = 10){
        if(!(bool) $this->getConfig()->getNested("earth.stagger-chunk-send", true)
            || $player->getLevel()->getFolderName() !== $this->universeName){
            return;
        }
        $assetPath = $this->getDataFolder() . basename((string) $this->getConfig()->getNested("earth.asset", "earth.bin.gz"));
        $asset = EarthAsset::load($assetPath);
        if(!($asset instanceof EarthAsset)){
            return;
        }
        list($width, $height, $length) = $asset->getSize();
        $center = $this->getUniverseEarthCenter();
        $originX = (int) $center[0] - (int) floor($width / 2);
        $originZ = (int) $center[2] - (int) floor($length / 2);
        $minChunkX = $originX >> 4;
        $maxChunkX = ($originX + $width - 1) >> 4;
        $minChunkZ = $originZ >> 4;
        $maxChunkZ = ($originZ + $length - 1) >> 4;
        $playerChunkX = ((int) floor($player->x)) >> 4;
        $playerChunkZ = ((int) floor($player->z)) >> 4;
        $coords = [];
        for($x = $minChunkX; $x <= $maxChunkX; ++$x){
            for($z = $minChunkZ; $z <= $maxChunkZ; ++$z){
                $dx = $x - $playerChunkX;
                $dz = $z - $playerChunkZ;
                $coords[] = [$x, $z, ($dx * $dx) + ($dz * $dz)];
            }
        }
        usort($coords, function($a, $b){
            if($a[2] === $b[2]){ return 0; }
            return $a[2] < $b[2] ? -1 : 1;
        });
        $this->earthChunkStreams[strtolower($player->getName())] = [
            "world" => strtolower($this->universeName),
            "coords" => $coords,
            "index" => 0,
            "ticks" => max(0, (int) $delayTicks)
        ];
    }

    private function tickEarthChunkStreams(){
        $interval = max(1, min(10, (int) $this->getConfig()->getNested("earth.chunk-send-interval-ticks", 2)));
        foreach(array_keys($this->earthChunkStreams) as $name){
            if(!isset($this->earthChunkStreams[$name])){ continue; }
            $stream = $this->earthChunkStreams[$name];
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player) || strtolower($player->getLevel()->getFolderName()) !== $stream["world"]){
                unset($this->earthChunkStreams[$name]);
                continue;
            }
            if((int) $stream["ticks"] > 0){
                --$this->earthChunkStreams[$name]["ticks"];
                continue;
            }
            $index = (int) $stream["index"];
            $coords = (array) $stream["coords"];
            if(!isset($coords[$index])){
                unset($this->earthChunkStreams[$name]);
                $this->syncUniverseTime($player);
                continue;
            }
            $this->forceChunkRefreshCoords($player, [$coords[$index]]);
            $this->earthChunkStreams[$name]["index"] = $index + 1;
            $this->earthChunkStreams[$name]["ticks"] = $interval - 1;
        }
    }

    /**
     * Determine the columns to scrub for a just-completed Nether -> normal hop.
     * Universe is special: its 64x64 Earth landmark is centred separately from
     * the player entry point, so clear the landmark area rather than only the
     * feet chunk. Other worlds are centred on the real landing position.
     */
    private function queueDestinationChunkScrub(Player $player, Position $target){
        if(!(bool) $this->getConfig()->getNested("render-cache-fix.enabled", true)){
            return;
        }
        $radius = max(1, min(3, (int) $this->getConfig()->getNested("render-cache-fix.radius", 2)));
        $world = strtolower($player->getLevel()->getFolderName());
        if($world === strtolower($this->universeName)){
            $earth = $this->getUniverseEarthCenter();
            $centerX = ((int) floor($earth[0])) >> 4;
            $centerZ = ((int) floor($earth[2])) >> 4;
        }else{
            $centerX = ((int) floor($target->x)) >> 4;
            $centerZ = ((int) floor($target->z)) >> 4;
        }
        $this->queueChunkScrubAt($player, $centerX, $centerZ, $radius, false);
    }

    /**
     * Public-facing/manual repair around the player's current visible region.
     * On an already-loaded world we only blank columns the core currently tracks
     * for this player, so an unseen column can never be left cached as air.
     */
    private function queueChunkScrub(Player $player, $radius = null){
        if($radius === null){
            $radius = (int) $this->getConfig()->getNested("render-cache-fix.radius", 2);
        }
        $this->queueChunkScrubAt(
            $player,
            ((int) floor($player->x)) >> 4,
            ((int) floor($player->z)) >> 4,
            $radius,
            true
        );
    }

    /**
     * MCPE 0.14 multiworld repair based on the old PocketMine workaround:
     * replace reused X/Z columns with a valid empty chunk, then shortly after
     * request the real target columns again. A real-only resend is insufficient.
     */
    private function queueChunkScrubAt(Player $player, $centerX, $centerZ, $radius, $trackedOnly){
        if(!(bool) $this->getConfig()->getNested("render-cache-fix.enabled", true)){
            return;
        }
        $radius = max(1, min(3, (int) $radius));
        $coords = $this->buildChunkRepairCoords($player, (int) $centerX, (int) $centerZ, $radius, (bool) $trackedOnly);
        if(count($coords) === 0){
            return;
        }
        $this->sendEmptyChunks($player, $coords);
        $this->chunkRefreshes[strtolower($player->getName())] = [
            "world" => strtolower($player->getLevel()->getFolderName()),
            "coords" => $coords,
            // restore once after 4 ticks and once after 24 ticks. The first real
            // destination stream normally starts on the tick after teleport;
            // the second pass repairs clients which ignored packets while the
            // dimension loading screen was still settling.
            "ticks" => 30,
            "passes" => 0
        ];
    }

    private function buildChunkRepairCoords(Player $player, $centerX, $centerZ, $radius, $trackedOnly){
        $used = $trackedOnly ? $this->getPlayerUsedChunks($player) : null;
        $coords = [];
        for($dx = -$radius; $dx <= $radius; ++$dx){
            for($dz = -$radius; $dz <= $radius; ++$dz){
                $x = $centerX + $dx;
                $z = $centerZ + $dz;
                if(is_array($used)){
                    $hash = Level::chunkHash($x, $z);
                    if(!isset($used[$hash])){
                        continue;
                    }
                }
                $coords[] = [$x, $z, abs($dx) + abs($dz)];
            }
        }
        usort($coords, function($a, $b){ return $a[2] === $b[2] ? 0 : ($a[2] < $b[2] ? -1 : 1); });
        return $coords;
    }

    /** Read Genisys Player::$usedChunks without changing its movement gate. */
    private function getPlayerUsedChunks(Player $player){
        if($this->usedChunksReflectionFailed){ return null; }
        try{
            if($this->usedChunksProperty === null){
                $class = new \ReflectionClass($player);
                while($class !== false && !$class->hasProperty("usedChunks")){
                    $class = $class->getParentClass();
                }
                if($class === false){
                    $this->usedChunksReflectionFailed = true;
                    return null;
                }
                $this->usedChunksProperty = $class->getProperty("usedChunks");
                $this->usedChunksProperty->setAccessible(true);
            }
            return (array) $this->usedChunksProperty->getValue($player);
        }catch(\Exception $e){
            $this->usedChunksReflectionFailed = true;
            $this->getLogger()->warning("Cannot inspect Genisys usedChunks for chunk scrub: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Construct the fixed-height 0.14 FullChunkData payload for an all-air
     * column. Layout: IDs, metadata, skylight, blocklight, heightmap, biome
     * colours, then a zero little-endian extra-data count. No tile NBT follows.
     */
    private function getEmptyChunkPayload(){
        if($this->emptyChunkPayload === null){
            $this->emptyChunkPayload =
                str_repeat("\x00", 32768) . // block IDs
                str_repeat("\x00", 16384) . // metadata
                str_repeat("\xff", 16384) . // full skylight for empty air
                str_repeat("\x00", 16384) . // block light
                str_repeat("\x00", 256) .   // height map
                str_repeat("\x00", 1024) .  // biome colours
                pack("V", 0);                // extra data count
        }
        return $this->emptyChunkPayload;
    }

    private function getChunkPacketOrder(Level $level){
        $provider = method_exists($level, "getProvider") ? $level->getProvider() : null;
        if($provider !== null){
            $class = get_class($provider);
            if(method_exists($class, "usesChunkSection") && call_user_func([$class, "usesChunkSection"])){
                return FullChunkDataPacket::ORDER_LAYERED;
            }
        }
        return FullChunkDataPacket::ORDER_COLUMNS;
    }

    private function sendEmptyChunks(Player $player, array $coords){
        $level = $player->getLevel();
        if($level === null){ return; }
        $payload = $this->getEmptyChunkPayload();
        $order = $this->getChunkPacketOrder($level);
        foreach($coords as $coord){
            $pk = new FullChunkDataPacket();
            $pk->chunkX = (int) $coord[0];
            $pk->chunkZ = (int) $coord[1];
            $pk->order = $order;
            $pk->data = $payload;
            // Batch it after the already-queued ChangeDimension packet. The
            // target core chunks are ordered on the next server tick.
            $player->batchDataPacket($pk);
        }
    }

    private function tickChunkRefreshes(){
        foreach($this->chunkRefreshes as $name => $refresh){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player) || strtolower($player->getLevel()->getFolderName()) !== $refresh["world"]){
                unset($this->chunkRefreshes[$name]);
                continue;
            }
            --$this->chunkRefreshes[$name]["ticks"];
            $ticks = (int) $this->chunkRefreshes[$name]["ticks"];
            if($ticks === 26 || $ticks === 6){
                $this->forceChunkRefreshCoords($player, (array) $refresh["coords"]);
                ++$this->chunkRefreshes[$name]["passes"];
            }
            if($ticks <= 0){
                unset($this->chunkRefreshes[$name]);
            }
        }
    }

    /** Request fresh REAL target data only after the all-air scrub packet. */
    private function forceChunkRefreshCoords(Player $player, array $coords){
        $level = $player->getLevel();
        if($level === null || !method_exists($level, "requestChunk")){
            return;
        }
        foreach($coords as $coord){
            $chunkX = (int) $coord[0];
            $chunkZ = (int) $coord[1];
            $level->loadChunk($chunkX, $chunkZ, true);
            if(method_exists($level, "isChunkGenerated") && !$level->isChunkGenerated($chunkX, $chunkZ)){
                $level->generateChunk($chunkX, $chunkZ, true);
            }
            $level->populateChunk($chunkX, $chunkZ, true);
            if(method_exists($level, "clearChunkCache")){
                $level->clearChunkCache($chunkX, $chunkZ);
            }
            // requestChunk() only sends to columns the core currently tracks;
            // this is desirable on the delayed restore pass and avoids touching
            // Genisys' usedChunks movement/teleport state ourselves.
            $level->requestChunk($chunkX, $chunkZ, $player);
        }
    }

    private function tickWorldResyncs(){
        foreach($this->worldResyncs as $name => $sync){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player) || strtolower($player->getLevel()->getFolderName()) !== $sync["world"]){
                unset($this->worldResyncs[$name]);
                continue;
            }
            $ticks = $this->worldResyncs[$name]["ticks"];
            if($ticks === 30 || $ticks === 15 || $ticks === 5){
                $level = $player->getLevel();
                if($level->getFolderName() === $this->universeName){
                    $this->syncUniverseTime($player);
                    // 1.0.12 uses native allowFlight only while physically in
                    // Universe. Exit cleanup is owned by the real Nether landing
                    // handshake, not by this routine.
                    $nativeSurvivalFlight = (bool) $this->getConfig()->getNested("space.survival-native-flight", true);
                    if($player->isCreative() || $nativeSurvivalFlight){
                        if(!$player->getAllowFlight()){ $player->setAllowFlight(true); }
                    }elseif($player->getAllowFlight()){
                        $this->disablePlayerFlight($player, false);
                    }
                }elseif($this->isPlanetWorld($level->getFolderName())){
                    $planet = $this->registry->getByWorld($level->getFolderName());
                    if($planet !== null){
                        $this->applyPlanetEnvironment($level, $planet);
                    }
                    if(!isset($this->flights[$name])){
                        $this->disablePlayerFlight($player, false);
                    }
                }else{
                    $level->sendTime();
                    // Do not touch flight abilities during Earth resync.
                    // Flight cleanup owns its own post-teleport state machine.
                }
            }
            --$this->worldResyncs[$name]["ticks"];
            $ticks = $this->worldResyncs[$name]["ticks"];
            if($ticks > 0){
                continue;
            }
            unset($this->worldResyncs[$name]);
        }
    }

    /**
     * Earth needs a stricter ordering than planet landings. Keep the temporary
     * flight permission through the Nether -> Earth teleport, let Genisys issue
     * its own same-world MovePlayer MODE_RESET, then revoke plugin flight.
     */
    private function queueEarthFlightPostureReset(Player $player, $restoreAllow = false){
        $name = strtolower($player->getName());
        if(isset($this->recentGroundFlightResets[$name])){
            unset($this->recentGroundFlightResets[$name], $this->flightPostureRestores[$name]);
            if(!$player->isCreative()){
                $this->disablePlayerFlight($player, false);
            }
            return;
        }
        $allowAfter = $player->isCreative() || (bool) $restoreAllow;
        if($allowAfter){
            unset($this->flightPostureRestores[$name]);
            if(!$player->getAllowFlight()){
                $player->setAllowFlight(true);
            }
            return;
        }
        // Deliberately do not call setAllowFlight(false) yet.
        $this->flightPostureRestores[$name] = [
            "ticks" => 40,
            "allow" => false,
            "world" => strtolower($player->getLevel()->getFolderName()),
            "earthReset" => true,
            "positionReset" => false
        ];
    }

    /**
     * End any client-side flying posture without fighting normal physics.
     *
     * Old builds used to force onGround=false and motionY=-0.08 for six
     * consecutive ticks.  That collides especially badly with water buoyancy
     * and produces the characteristic up/down rubber-banding on ocean worlds.
     */
    private function resetFlightPosture(Player $player, $restoreAllow = false){
        $name = strtolower($player->getName());
        if(isset($this->recentGroundFlightResets[$name])){
            unset($this->recentGroundFlightResets[$name], $this->flightPostureRestores[$name]);
            if(!$player->isCreative()){
                $this->disablePlayerFlight($player, false);
            }
            return;
        }
        $allowAfter = $player->isCreative() || (bool) $restoreAllow;

        // Creative flight is a native game-mode ability. Never use a landing
        // cleanup to revoke it or force the player out of an already-valid
        // creative flying state.
        if($allowAfter){
            unset($this->flightPostureRestores[$name]);
            if(!$player->getAllowFlight()){
                $player->setAllowFlight(true);
            }
            if(method_exists($player, "resetFallDistance")){
                $player->resetFallDistance();
            }
            return;
        }

        // For survival/adventure, only revoke the temporary permission granted
        // by this plugin. Never fake a game-mode change or continuously force
        // vertical motion. The single MODE_RESET, when needed, is deferred until
        // the destination 3x3 chunk gate is confirmed ready.
        $this->flightPostureRestores[$name] = [
            "ticks" => 40,
            "allow" => false,
            "world" => strtolower($player->getLevel()->getFolderName()),
            "earthReset" => false,
            "planetReset" => true,
            "positionReset" => false
        ];
        // Always emit the first AdventureSettings immediately. Subsequent passes
        // below intentionally re-send the same allow_fly=false packet even after
        // the server-side flag is already false. MCPE 0.14 can miss the first one
        // while ChangeDimension/PLAYER_SPAWN is still settling.
        $this->disablePlayerFlight($player, false);
    }

    private function tickFlightPostureRestores(){
        foreach($this->flightPostureRestores as $name => $restore){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player)){
                unset($this->flightPostureRestores[$name]);
                continue;
            }
            if(isset($this->flights[$name]) || strtolower($player->getLevel()->getFolderName()) !== $restore["world"]){
                unset($this->flightPostureRestores[$name]);
                continue;
            }

            // A player may switch to creative while this short cleanup is
            // pending. In that case native flight wins immediately.
            if($player->isCreative()){
                if(!$player->getAllowFlight()){
                    $player->setAllowFlight(true);
                }
                unset($this->flightPostureRestores[$name]);
                continue;
            }

            --$this->flightPostureRestores[$name]["ticks"];
            $ticks = (int) $this->flightPostureRestores[$name]["ticks"];
            $earthReset = !empty($this->flightPostureRestores[$name]["earthReset"]);
            if($earthReset){
                if(empty($this->flightPostureRestores[$name]["positionReset"])){
                    // Modern release path: the arrival finalizer waits on the same
                    // real 3x3 chunk gate as Genisys and then performs MODE_RESET.
                    // Do not start a competing same-world teleport while that gate
                    // is pending.
                    if(isset($this->clientArrivalFinalizers[$name])){
                        if($ticks <= 1){
                            $this->flightPostureRestores[$name]["ticks"] = 1;
                        }
                        continue;
                    }

                    // Compatibility fallback for direct/same-world Earth returns
                    // which did not pass through a dimension transition.
                    if($ticks <= 32){
                        $same = new Position($player->x, $player->y, $player->z, $player->getLevel());
                        if($player->teleport($same, $player->yaw, $player->pitch)){
                            $this->flightPostureRestores[$name]["positionReset"] = true;
                            $this->flightPostureRestores[$name]["ticks"] = 12;
                            $this->sendClientFlightPostureReset($player, true, null);
                        }
                    }
                    if($ticks <= 0){
                        $this->getLogger()->warning("Earth position reset timed out for " . $player->getName() . "; revoking temporary flight with fallback.");
                        $this->disablePlayerFlight($player, false);
                        unset($this->flightPostureRestores[$name]);
                    }
                    continue;
                }
                if($ticks === 6){
                    $this->disablePlayerFlight($player, false);
                    $this->sendClientFlightPostureReset($player, false, null);
                }
                if($ticks <= 0){
                    $this->disablePlayerFlight($player, false);
                    $this->sendClientFlightPostureReset($player, false, null);
                    unset($this->flightPostureRestores[$name]);
                }
                continue;
            }

            $planetReset = !empty($this->flightPostureRestores[$name]["planetReset"]);
            if($planetReset){
                // The real reset now happens on the safe Nether relay platform.
                // Do not teleport again on the planet: another MODE_RESET while
                // the client is finishing Nether -> Overworld can itself revive
                // the half-flight movement state. Only keep reinforcing the
                // allow_fly=false bit for ~2 seconds after arrival.
                if(($ticks % 4) === 0){
                    $this->disablePlayerFlight($player, false);
                }
                // The authoritative MODE_RESET is deliberately deferred to
                // tickClientArrivalFinalizers(), where we know the target 3x3 has
                // been sent. Here we only keep re-sending AdventureSettings.
                if($ticks <= 0){
                    $this->disablePlayerFlight($player, false);
                    unset($this->flightPostureRestores[$name]);
                }
                continue;
            }
            if($ticks <= 0){
                unset($this->flightPostureRestores[$name]);
            }
        }
    }

    /**
     * Remove only flight permission granted by this plugin.
     *
     * This Genisys 0.14.3 branch only stores/sends the allow_fly AdventureSettings
     * bit; active flying posture is client-side. Sending a synthetic game-mode
     * packet as a substitute proved unsafe, so survival transitions are grounded
     * on the real Nether relay before the destination hop. Forks which expose
     * setFlying() are still handled opportunistically. Creative mode is never
     * forced out of flight.
     */
    private function disablePlayerFlight(Player $player, $resetPosition){
        if($player->isCreative()){
            if(!$player->getAllowFlight()){
                $player->setAllowFlight(true);
            }
            if(method_exists($player, "resetFallDistance")){
                $player->resetFallDistance();
            }
            return;
        }

        // Do not guard this with getAllowFlight(). The whole point of this
        // function is to synchronize the *client*. After the first call the
        // server flag is already false, but the 0.14 client may have ignored that
        // packet during a dimension transition. setAllowFlight(false) also calls
        // sendSettings(), so repeated calls are intentional and cheap.
        $player->setAllowFlight(false);
        if(method_exists($player, "setFlying")){
            $player->setFlying(false);
        }
        // $resetPosition is intentionally ignored on MCPE 0.14. The core owns
        // the authoritative teleport MODE_RESET after its destination chunk gate.
        if(method_exists($player, "resetFallDistance")){
            $player->resetFallDistance();
        }
    }

    private function enterPlanet(Player $player, array $sphere){
        $planet = $this->registry->discover($sphere);
        $level = $this->loadPlanetWorld($planet);
        if($level === null){
            $player->sendMessage(TextFormat::RED . "空间通道繁忙或星球加载失败，请稍后重试。");
            return;
        }
        $this->applyPlanetEnvironment($level, $planet);
        $spawnY = $this->getPlanetSpawnY($planet);
        $level->setSpawnLocation(new Vector3(8.5, $spawnY, 8.5));
        if(!$this->teleportAcrossWorld($player, new Position(8.5, $spawnY, 8.5, $level))){
            return;
        }
        if(isset($this->dimensionTransitions[strtolower($player->getName())])){ return; }
        $this->completePlanetArrival($player, $planet);
    }

    private function completePlanetArrival(Player $player, array $planet){
        $this->applyPlanetEnvironment($player->getLevel(), $planet);
        // Stop the last Universe velocity assist before revoking flight. A final
        // SetEntityMotion from the previous world can otherwise survive the hop
        // and make the client feel as if it is still half-flying. One zero vector
        // is safe; the old bug came from forcing downward motion every tick.
        $this->clearSpaceFlightAssist($player);
        if(!$player->isCreative()){
            $player->setMotion(new Vector3(0, 0, 0));
        }
        $this->resetFlightPosture($player, false);
        $this->giveAircraft($player);
        $this->sendPlanetReport($player, $planet);
        $gravityName = $this->getGravityDisplayName((array) ($planet["visual"]["gravity"] ?? []));
        $player->sendMessage(TextFormat::AQUA . "已抵达 " . $planet["world"] . "（" . $gravityName . "），飞行器已返还。向上飞越132格可返回宇宙。");
    }

    private function loadPlanetWorld(array $planet){
        $server = $this->getServer();
        $world = $planet["world"];
        if($server->isLevelLoaded($world)){
            unset($this->unloadAt[$world]);
            return $server->getLevelByName($world);
        }
        if(count($this->getLoadedPlanetNames()) >= (int) $this->getConfig()->getNested("planet-worlds.max-loaded", 4)){
            $this->unloadOldestEmptyPlanet();
            if(count($this->getLoadedPlanetNames()) >= (int) $this->getConfig()->getNested("planet-worlds.max-loaded", 4)){
                return null;
            }
        }
        $this->restoreArchivedWorld($world);
        if($server->isLevelGenerated($world)){
            $server->loadLevel($world);
        }else{
            $settings = $this->registry->getGeneratorSettings($planet);
            $server->generateLevel($world, (int) $planet["seed"], PlanetGenerator::class, ["preset" => json_encode($settings)]);
            if(!$server->isLevelLoaded($world)){
                $server->loadLevel($world);
            }
        }
        unset($this->unloadAt[$world]);
        return $server->getLevelByName($world);
    }

    private function applyPlanetEnvironment($level, array $planet){
        $visual = $planet["visual"];
        $level->setTime((int) $visual["time"]);
        if(($visual["timeMode"] ?? "cycle") === "cycle"){
            $level->startTime();
        }else{
            $level->stopTime();
        }
        $level->getWeather()->setCanCalculate(false);
        if(($visual["weather"] ?? "clear") === "cycle"){
            $this->updatePlanetWeatherCycle($level, $planet);
        }else{
            $level->getWeather()->setWeather(($visual["weather"] ?? "clear") === "rain" ? 1 : 0, 12000);
        }
    }

    private function updatePlanetWeatherCycle($level, array $planet){
        // 不启用核心 Weather::calcWeather()，避免该核心损坏的闪电实体；每 6 分钟确定一次晴雨。
        $phase = (int) floor((time() + ((int) $planet["seed"] % 360)) / 360);
        $worldKey = strtolower($planet["world"]);
        if(isset($this->planetWeatherPhase[$worldKey]) && $this->planetWeatherPhase[$worldKey] === $phase){
            return;
        }
        $this->planetWeatherPhase[$worldKey] = $phase;
        $hash = crc32($planet["world"] . ":weather:" . $phase);
        if($hash < 0){ $hash += 4294967296; }
        $level->getWeather()->setCanCalculate(false);
        $level->getWeather()->setWeather(($hash % 100) < 35 ? 1 : 0, 7200);
    }

    private function getPlanetSpawnY(array $planet){
        $level = $this->getServer()->getLevelByName((string) $planet["world"]);
        if($level !== null){
            $level->loadChunk(0, 0, true);
            $level->populateChunk(0, 0, true);
            if(!method_exists($level, "isChunkPopulated") || $level->isChunkPopulated(0, 0)){
                return (float) $this->findPlanetArrivalPosition($level, $planet, 8, 8)->y;
            }
        }
        // This value is only a temporary placeholder while the target chunk is
        // still generating. prepareDimensionTarget() recomputes the real safe
        // Y before the Nether -> planet hop is allowed to complete.
        $minimum = (($planet["visual"]["climate"] ?? "normal") === "ocean") ? ((int) ($planet["visual"]["seaLevel"] ?? 63) + 3) : 100;
        return (float) min(125, max(4, $minimum));
    }

    /** Find a real above-surface landing point in an already populated chunk. */
    private function findPlanetArrivalPosition($level, array $planet, $baseX = 8, $baseZ = 8){
        $seaLevel = (int) ($planet["visual"]["seaLevel"] ?? 63);
        $isOcean = (($planet["visual"]["climate"] ?? "normal") === "ocean");
        $minimumY = $isOcean ? min(123, $seaLevel + 2) : 4;

        // Stay well inside the central chunk.  After population has finished,
        // scan actual blocks (including water), choose the highest surface and
        // then CARVE the player's 3x3x3 body space.  This makes the destination
        // valid even if height maps or legacy population metadata are stale.
        $candidates = [[0,0], [1,0], [-1,0], [0,1], [0,-1], [2,0], [-2,0], [0,2], [0,-2], [2,2], [-2,2], [2,-2], [-2,-2], [4,0], [-4,0], [0,4], [0,-4]];
        $best = null;
        foreach($candidates as $offset){
            $x = max(2, min(13, (int) $baseX + $offset[0]));
            $z = max(2, min(13, (int) $baseZ + $offset[1]));
            $top = -1;
            for($y = 126; $y >= 1; --$y){
                if($level->getBlockIdAt($x, $y, $z) !== Block::AIR){
                    $top = $y;
                    break;
                }
            }
            $arrivalY = max($minimumY, $top >= 0 ? $top + 2 : $minimumY);
            if($arrivalY <= 124){
                $best = [$x, $arrivalY, $z];
                break;
            }
        }
        if($best === null){
            // Last resort near the 128-block ceiling: create an insertion pocket.
            $best = [max(2, min(13, (int) $baseX)), 124, max(2, min(13, (int) $baseZ))];
        }
        $this->carvePlanetArrivalPocket($level, $best[0], $best[1], $best[2]);
        return new Position($best[0] + 0.5, (float) $best[1], $best[2] + 0.5, $level);
    }

    private function carvePlanetArrivalPocket($level, $x, $feetY, $z){
        $x = (int) floor($x);
        $z = (int) floor($z);
        $feetY = max(3, min(124, (int) floor($feetY)));
        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                for($dy = 0; $dy <= 2; ++$dy){
                    $level->setBlock(new Vector3($x + $dx, $feetY + $dy, $z + $dz), Block::get(Block::AIR), true, false);
                }
            }
        }
    }

    private function queuePlanetLandingGuard(Player $player, Position $target){
        if($target->getLevel() === null){ return; }
        $this->planetLandingGuards[strtolower($player->getName())] = [
            "ticks" => 80,
            "world" => strtolower($target->getLevel()->getFolderName()),
            "x" => (float) $target->x,
            "y" => (float) $target->y,
            "z" => (float) $target->z
        ];
    }

    private function isSolidForLanding($id){
        $id = (int) $id;
        return $id !== Block::AIR && $id !== Block::WATER && $id !== Block::STILL_WATER;
    }

    private function tickPlanetLandingGuards(){
        foreach($this->planetLandingGuards as $name => $guard){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player)){
                unset($this->planetLandingGuards[$name]);
                continue;
            }
            --$this->planetLandingGuards[$name]["ticks"];
            $ticks = (int) $this->planetLandingGuards[$name]["ticks"];
            $world = strtolower($player->getLevel()->getFolderName());
            if($world !== $guard["world"]){
                if($ticks <= 0){ unset($this->planetLandingGuards[$name]); }
                continue;
            }

            // Only intervene when the player's body is actually inside a solid
            // block.  Falling into ocean water or naturally descending is valid.
            $px = (int) floor($player->x);
            $py = max(1, min(126, (int) floor($player->y)));
            $pz = (int) floor($player->z);
            $blocked = $this->isSolidForLanding($player->getLevel()->getBlockIdAt($px, $py, $pz))
                || $this->isSolidForLanding($player->getLevel()->getBlockIdAt($px, min(127, $py + 1), $pz));
            if($blocked){
                $sx = (int) floor($guard["x"]);
                $sy = (int) floor($guard["y"]);
                $sz = (int) floor($guard["z"]);
                $this->carvePlanetArrivalPocket($player->getLevel(), $sx, $sy, $sz);
                $safe = new Position((float) $guard["x"], (float) $guard["y"], (float) $guard["z"], $player->getLevel());
                if(method_exists($player, "teleportImmediate")){
                    $player->teleportImmediate($safe);
                }else{
                    $player->teleport($safe);
                }
                $player->setMotion(new Vector3(0, 0, 0));
                $this->getLogger()->warning("Landing guard rescued " . $player->getName() . " from a solid block in " . $world . ".");
            }
            if($ticks <= 0){
                unset($this->planetLandingGuards[$name]);
            }
        }
    }

    /**
     * Reduce a legacy all-wool planet chunk to a visible wool shell.
     *
     * This is deliberately an opacity-preserving conversion (wool -> stone) for
     * hidden interior blocks, so heightmaps and skylight do not need rebuilding.
     * The coloured exterior/cave surfaces remain wool. New worlds already use the
     * same shell rule in PlanetGenerator, while this path repairs old saves.
     */
    private function stabilizeWoolPlanetArea($level, array $planet, $centerChunkX, $centerChunkZ, $radius = 1, Player $refreshPlayer = null){
        if((int) ($planet["block"]["id"] ?? 0) !== Block::WOOL){ return 0; }
        $depth = max(1, min(8, (int) $this->getConfig()->getNested("planet-worlds.wool-shell-depth", 3)));
        $radius = max(0, min(2, (int) $radius));
        $changedTotal = 0;
        $worldKey = strtolower($level->getFolderName());
        if(!isset($this->woolChunkCompactions[$worldKey])){ $this->woolChunkCompactions[$worldKey] = []; }

        for($dx = -$radius; $dx <= $radius; ++$dx){
            for($dz = -$radius; $dz <= $radius; ++$dz){
                $chunkX = (int) $centerChunkX + $dx;
                $chunkZ = (int) $centerChunkZ + $dz;
                $hash = $chunkX . ":" . $chunkZ;
                if(isset($this->woolChunkCompactions[$worldKey][$hash])){ continue; }
                $level->loadChunk($chunkX, $chunkZ, true);
                $level->populateChunk($chunkX, $chunkZ, true);
                if(method_exists($level, "isChunkPopulated") && !$level->isChunkPopulated($chunkX, $chunkZ)){
                    continue;
                }
                $changed = $this->compactLegacyWoolChunk($level, $chunkX, $chunkZ, $depth);
                $treeChanged = $this->backfillLegacyWoolTrees($level, $chunkX, $chunkZ);
                $this->woolChunkCompactions[$worldKey][$hash] = true;
                if($changed > 0 || $treeChanged > 0){
                    $changedTotal += $changed + $treeChanged;
                    if(method_exists($level, "clearChunkCache")){
                        $level->clearChunkCache($chunkX, $chunkZ);
                    }
                    if($refreshPlayer instanceof Player && $refreshPlayer->getLevel() === $level){
                        $level->requestChunk($chunkX, $chunkZ, $refreshPlayer);
                    }
                }
            }
        }
        return $changedTotal;
    }


    /**
     * Compatibility decoration for wool chunks generated by <=0.6.3.
     *
     * Those chunks had their biome ID overwritten before Normal::populateChunk(),
     * so the original biome Tree populator never ran. New chunks no longer need
     * this path. The positions are deterministic and each candidate is idempotent:
     * if the brown-wool trunk already exists, the same candidate is skipped.
     * Trees are intentionally kept inside one chunk to avoid async cross-chunk
     * writes on old Genisys.
     */
    private function backfillLegacyWoolTrees($level, $chunkX, $chunkZ){
        $chunk = $level->getChunk((int) $chunkX, (int) $chunkZ);
        if($chunk === null){ return 0; }

        $worldKey = strtolower($level->getFolderName());
        if(!isset($this->woolTreeBackfills[$worldKey])){ $this->woolTreeBackfills[$worldKey] = []; }
        $hash = ((int) $chunkX) . ":" . ((int) $chunkZ);
        if(isset($this->woolTreeBackfills[$worldKey][$hash])){ return 0; }
        $this->woolTreeBackfills[$worldKey][$hash] = true;

        // 0.6.5 uses the same deterministic decorator for new and legacy wool
        // chunks. Legacy=true deliberately falls back to surface colour because
        // <=0.6.3 may already have replaced the original biome ID. This also
        // backfills cactus on sand-coloured wool, which 0.6.4 forgot entirely.
        return PlanetGenerator::decorateWoolVegetation(
            $chunk,
            (int) $chunkX,
            (int) $chunkZ,
            (int) $level->getSeed(),
            true
        );
    }

    private function compactLegacyWoolChunk($level, $chunkX, $chunkZ, $depth){
        $chunk = $level->getChunk((int) $chunkX, (int) $chunkZ);
        if($chunk === null){ return 0; }
        $changed = 0;
        $baseX = ((int) $chunkX) << 4;
        $baseZ = ((int) $chunkZ) << 4;
        for($x = 0; $x < 16; ++$x){
            for($z = 0; $z < 16; ++$z){
                for($y = 1; $y < 127; ++$y){
                    if($chunk->getBlockId($x, $y, $z) !== Block::WOOL){ continue; }
                    $wx = $baseX + $x;
                    $wz = $baseZ + $z;
                    if($this->isWoolBlockNearAir($level, $wx, $y, $wz, $depth)){
                        continue;
                    }
                    // Hidden filler is intentionally boring stone. Ores were never
                    // converted to wool by the old generator and remain untouched.
                    $chunk->setBlockId($x, $y, $z, Block::STONE);
                    $chunk->setBlockData($x, $y, $z, 0);
                    ++$changed;
                }
            }
        }
        if($changed > 0 && method_exists($chunk, "setChanged")){
            $chunk->setChanged();
        }
        return $changed;
    }

    private function isWoolBlockNearAir($level, $x, $y, $z, $depth){
        $dirs = [[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]];
        foreach($dirs as $dir){
            for($i = 1; $i <= $depth; ++$i){
                $ny = $y + ($dir[1] * $i);
                if($ny < 0 || $ny > 127){ return true; }
                if($level->getBlockIdAt($x + ($dir[0] * $i), $ny, $z + ($dir[2] * $i)) === Block::AIR){
                    return true;
                }
            }
        }
        return false;
    }

    private function ensureEarthWorld(){
        $world = trim((string) $this->getConfig()->getNested("earth.return-world", "earth"));
        if($world === ""){
            $world = "earth";
        }
        $server = $this->getServer();
        if(!$server->isLevelLoaded($world)){
            if($server->isLevelGenerated($world)){
                $server->loadLevel($world);
            }else{
                // STARTUP 阶段核心尚未注册 "normal" 别名，必须直接传入内置类名。
                $server->generateLevel($world, null, Normal::class);
                if(!$server->isLevelLoaded($world)){
                    $server->loadLevel($world);
                }
            }
        }
        $level = $server->getLevelByName($world);
        if($level !== null){
            $surface = $this->getEarthSurfaceSpawn($level);
            if(!($surface instanceof Position)){
                return null;
            }
            $level->setSpawnLocation(new Vector3($surface->x, $surface->y, $surface->z));
            $level->save(true);
        }
        return $level;
    }

    private function returnToConfiguredWorld(Player $player){
        $level = $this->ensureEarthWorld();
        if($level === null){
            $player->sendMessage(TextFormat::YELLOW . "地球出生区域仍在生成，请稍后再试。");
            return;
        }
        $surface = $this->getEarthSurfaceSpawn($level);
        if(!($surface instanceof Position)){
            $player->sendMessage(TextFormat::YELLOW . "地球地表出生点尚未准备好，请稍后再试。");
            return;
        }
        $target = $surface;
        if(!$this->teleportAcrossWorld($player, $target)){
            return;
        }
        if(isset($this->dimensionTransitions[strtolower($player->getName())])){ return; }
        $restoreFlight = $this->leaveCustomRules($player, true);
        $this->queueEarthFlightPostureReset($player, $restoreFlight);
        $this->giveAircraft($player);
        $this->sendEarthReport($player);
        $player->sendMessage(TextFormat::GREEN . "已返回 " . $level->getFolderName() . "，飞行器已返还。地球返回世界可在 config.yml 的 earth.return-world 修改。");
    }

    public function onInteract(PlayerInteractEvent $event){
        if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_AIR && $event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            return;
        }
        if(!$this->isAircraftItem($event->getItem())){
            return;
        }
        $event->setCancelled(true);
        $this->startFlight($event->getPlayer());
    }

    private function startFlight(Player $player){
        $name = strtolower($player->getName());
        unset($this->flightPostureRestores[$name]);
        if(isset($this->flights[$name])){
            $player->sendMessage(TextFormat::YELLOW . "你已经在飞行器中。");
            return;
        }
        if($player->getLevel()->getFolderName() === $this->universeName){
            $player->sendMessage(TextFormat::YELLOW . "宇宙中可以直接双击跳跃飞行，不需要飞行器。");
            return;
        }
        if(!$this->consumeAircraft($player)){
            return;
        }
        $chunk = $player->getLevel()->getChunk(((int) $player->x) >> 4, ((int) $player->z) >> 4, true);
        $nbt = new CompoundTag("", [
            new ListTag("Pos", [new DoubleTag(0, $player->x), new DoubleTag(1, $player->y + 0.5), new DoubleTag(2, $player->z)]),
            new ListTag("Motion", [new DoubleTag(0, 0), new DoubleTag(1, 0), new DoubleTag(2, 0)]),
            new ListTag("Rotation", [new FloatTag(0, $player->yaw), new FloatTag(1, 0)])
        ]);
        $entity = Entity::createEntity("Minecart", $chunk, $nbt);
        if(!($entity instanceof Entity)){
            $this->giveAircraft($player);
            $player->sendMessage(TextFormat::RED . "飞行器生成失败。");
            return;
        }
        $entity->spawnToAll();
        if(!$player->linkEntity($entity)){
            $entity->close();
            $this->giveAircraft($player);
            $player->sendMessage(TextFormat::RED . "无法坐上飞行器。");
            return;
        }
        $this->flights[$name] = [
            "entity" => $entity,
            "origin" => $player->getLevel()->getFolderName(),
            "anchor" => [$entity->x, $entity->y, $entity->z],
            "launchY" => (float) $entity->y,
            "launched" => false,
            "holdStart" => null,
            "lastForwardAt" => 0.0,
            "inputMotY" => 0.0,
            "lastCountdown" => null,
            "launchTick" => null,
            "currentSpeed" => 0.0
        ];
        $this->enterCustomRules($player, true, $player->getLevel()->getFolderName() === $this->universeName);
        $countdown = max(1, (int) $this->getConfig()->getNested("aircraft.launch-hold-seconds", 10));
        $player->sendMessage(TextFormat::AQUA . "飞行器已就位。持续按住前进键 " . $countdown . " 秒点火；松开会重置倒计时。");
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event){
        $packet = $event->getPacket();
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        // Freeze normal movement while a dimension relay is active. Real Nether
        // keeps the old server-side handling; the client-only relay must cancel
        // MovePlayerPacket entirely because the server entity deliberately stays
        // in Universe while the client is looking at the synthetic dimension.
        if($packet instanceof MovePlayerPacket && isset($this->dimensionTransitions[$name])){
            if(($this->dimensionTransitions[$name]["relayMode"] ?? "real") === "virtual"){
                $event->setCancelled(true);
            }
            return;
        }
        if(!($packet instanceof PlayerInputPacket)){
            return;
        }
        $now = microtime(true);

        if(!isset($this->flights[$name])){
            return;
        }

        $motY = (float) $packet->motY;
        $threshold = (float) $this->getConfig()->getNested("aircraft.forward-input-threshold", 0.5);
        $this->flights[$name]["inputMotY"] = $motY;
        if($motY >= $threshold){
            $this->flights[$name]["lastForwardAt"] = $now;
            if(!$this->flights[$name]["launched"] && $this->flights[$name]["holdStart"] === null){
                $this->flights[$name]["holdStart"] = $now;
                $this->flights[$name]["lastCountdown"] = null;
            }
        }elseif(!$this->flights[$name]["launched"]){
            $this->resetLaunchCountdown($name, $player);
        }

        if(isset($this->inputDebug[$name])){
            if(!isset($this->inputDebugAt[$name]) || ($now - $this->inputDebugAt[$name]) >= 0.25){
                $this->inputDebugAt[$name] = $now;
                $message = "[UniverseInput] " . $player->getName() . " motY=" . round($motY, 3)
                    . " motX=" . round((float) $packet->motX, 3)
                    . " jumping=" . ($packet->jumping ? "1" : "0")
                    . " sneaking=" . ($packet->sneaking ? "1" : "0");
                $player->sendMessage(TextFormat::GRAY . $message);
                $this->getLogger()->info($message);
            }
        }
    }

    public function onDataPacketSend(DataPacketSendEvent $event){
        $packet = $event->getPacket();
        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        // During the virtual Nether scrub the server is still physically in the
        // source Universe. Suppress ordinary source chunk/movement packets so
        // they cannot overwrite the synthetic client scene. Packets emitted by
        // the relay itself set a short-lived bypass flag.
        if(isset($this->dimensionTransitions[$name])
            && ($this->dimensionTransitions[$name]["relayMode"] ?? "real") === "virtual"
            && empty($this->virtualRelayPacketBypass[$name])){
            if($packet instanceof FullChunkDataPacket || $packet instanceof MovePlayerPacket){
                $event->setCancelled(true);
                return;
            }
        }

        // Absolute night guard: no SetTimePacket describing daylight is ever
        // allowed to leave the server while this player is in Universe. This
        // also catches core login/switchLevel packets that can race onJoin().
        if($packet instanceof SetTimePacket && $player->getLevel()->getFolderName() === $this->universeName){
            $packet->time = $this->getUniverseNightTime();
            $packet->started = false;
        }
        if(!isset($this->dimensionTransitions[$name])){ return; }
        $netherName = strtolower((string) $this->getConfig()->getNested("dimension-transfer.nether-world", "nether"));
        if(strtolower($player->getLevel()->getFolderName()) !== $netherName){ return; }

        if($packet instanceof FullChunkDataPacket){
            $pos = $this->getNetherRelayPosition();
            $centerX = ((int) floor($pos[0])) >> 4;
            $centerZ = ((int) floor($pos[2])) >> 4;
            $chunkX = (int) $packet->chunkX;
            $chunkZ = (int) $packet->chunkZ;
            if(abs($chunkX - $centerX) <= 1 && abs($chunkZ - $centerZ) <= 1){
                $this->dimensionTransitions[$name]["netherChunks"][$chunkX . ":" . $chunkZ] = true;
            }
            return;
        }
        if($packet instanceof PlayStatusPacket && $packet->status === PlayStatusPacket::PLAYER_SPAWN){
            $this->dimensionTransitions[$name]["serverReady"] = true;
            $this->getLogger()->debug("Nether PLAYER_SPAWN queued for " . $player->getName() . "; waiting for 3x3 FullChunkData/usedChunks gate before dwell.");
        }
    }

    private function resetLaunchCountdown($name, Player $player){
        if(!isset($this->flights[$name]) || $this->flights[$name]["launched"] || $this->flights[$name]["holdStart"] === null){
            return;
        }
        $this->flights[$name]["holdStart"] = null;
        $this->flights[$name]["lastCountdown"] = null;
        $player->sendPopup(TextFormat::RED . "点火倒计时已重置");
    }

    private function tickFlights($currentTick){
        $baseSpeed = (float) $this->getConfig()->getNested("aircraft.ascent-speed", 0.38);
        $acceleration = max(0.0, (float) $this->getConfig()->getNested("aircraft.forward-acceleration-per-tick", 0.006));
        $deceleration = max(0.0, (float) $this->getConfig()->getNested("aircraft.release-deceleration-per-tick", 0.012));
        $maxSpeed = max($baseSpeed, (float) $this->getConfig()->getNested("aircraft.max-ascent-speed", 0.55));
        $holdSeconds = max(1, (int) $this->getConfig()->getNested("aircraft.launch-hold-seconds", 10));
        $inputTimeout = max(0.2, (float) $this->getConfig()->getNested("aircraft.input-timeout-seconds", 0.75));
        $ascentDistance = max(16.0, (float) $this->getConfig()->getNested("aircraft.transition-height", 132));
        foreach($this->flights as $name => $flight){
            $player = $this->getServer()->getPlayerExact($name);
            $entity = $flight["entity"];
            if(!($player instanceof Player) || !($entity instanceof Entity) || $entity->closed || !$player->isAlive()){
                $this->endFlight($name, $player, true);
                continue;
            }
            $now = microtime(true);
            if(!$flight["launched"]){
                $anchor = $flight["anchor"];
                $waitingPosition = new Vector3($anchor[0], $anchor[1], $anchor[2]);
                $entity->setPosition($waitingPosition);
                $entity->setMotion(new Vector3(0, 0, 0));
                $rider = new Vector3($anchor[0], $anchor[1] + 0.5, $anchor[2]);
                $player->setPosition($rider);

                if($flight["holdStart"] === null){
                    continue;
                }
                if(($now - $flight["lastForwardAt"]) > $inputTimeout){
                    $this->resetLaunchCountdown($name, $player);
                    continue;
                }
                $elapsed = $now - $flight["holdStart"];
                $remaining = max(0, (int) ceil($holdSeconds - $elapsed));
                if($flight["lastCountdown"] !== $remaining){
                    $this->flights[$name]["lastCountdown"] = $remaining;
                    $player->sendPopup(TextFormat::YELLOW . "火箭点火倒计时：" . $remaining);
                }
                if($elapsed < $holdSeconds){
                    continue;
                }
                $this->flights[$name]["launched"] = true;
                $this->flights[$name]["launchTick"] = (int) $currentTick;
                $this->flights[$name]["currentSpeed"] = $baseSpeed;
                $flight["launched"] = true;
                $flight["launchTick"] = (int) $currentTick;
                $flight["currentSpeed"] = $baseSpeed;
                $player->sendMessage(TextFormat::GREEN . "点火成功！继续按住前进键可加速上升。");
            }

            // 0.14客户端在点火同一刻同时接收高速位移和粒子容易崩溃，先平滑升空半秒。
            $launchAge = $flight["launchTick"] === null ? 0 : ((int) $currentTick - (int) $flight["launchTick"]);
            $forwardHeld = $launchAge >= 10 && ($now - $flight["lastForwardAt"]) <= $inputTimeout;
            $speed = (float) $flight["currentSpeed"];
            if($forwardHeld){
                $speed = min($maxSpeed, $speed + $acceleration);
            }else{
                $speed = max($baseSpeed, $speed - $deceleration);
            }
            $this->flights[$name]["currentSpeed"] = $speed;
            $next = new Vector3($entity->x, $entity->y + $speed, $entity->z);
            $entity->setPosition($next);
            // setPosition()只修改服务端坐标；显式排队MoveEntityPacket后，0.14客户端才会显示矿车和乘客连续上升。
            $entity->getLevel()->addEntityMovement(
                ((int) $entity->x) >> 4,
                ((int) $entity->z) >> 4,
                $entity->getId(),
                $entity->x,
                $entity->y + $entity->getEyeHeight(),
                $entity->z,
                $entity->yaw,
                $entity->pitch,
                $entity->yaw
            );
            // setMotion() 是公开 API，并会在实体内部调用受保护的 updateMovement() 同步客户端。
            $entity->setMotion(new Vector3(0, $speed, 0));
            // 0.14 客户端不会可靠地让乘客镜头跟随插件移动的矿车，因此同时同步玩家坐标。
            $rider = new Vector3($next->x, $next->y + 0.5, $next->z);
            $player->setPosition($rider);
            $particleInterval = max(1, (int) $this->getConfig()->getNested("aircraft.particle-interval-ticks", 5));
            if($launchAge >= 10 && ($currentTick % $particleInterval) === 0){
                $this->emitRocketParticles($entity);
            }
            if(($entity->y - (float) $flight["launchY"]) < $ascentDistance){
                continue;
            }
            $origin = $flight["origin"];
            $holdPosition = new Vector3($player->x, $player->y, $player->z);
            $this->endFlight($name, $player, false);
            // 必须先让0.14客户端处理下车包，再跨世界；同一刻执行会被客户端延迟到手动跳车。
            $this->flightTransitions[$name] = [
                "ticks" => 6,
                "origin" => $origin,
                "hold" => $holdPosition
            ];
            $player->setMotion(new Vector3(0, 0, 0));
            $player->sendMessage(TextFormat::YELLOW . "已到达大气层边缘，正在脱离飞行器……");
        }
    }

    private function tickFlightTransitions(){
        foreach($this->flightTransitions as $name => $transition){
            $player = $this->getServer()->getPlayerExact($name);
            if(!($player instanceof Player)){
                unset($this->flightTransitions[$name]);
                continue;
            }
            $player->setPosition($transition["hold"]);
            $player->setMotion(new Vector3(0, 0, 0));
            --$this->flightTransitions[$name]["ticks"];
            if($this->flightTransitions[$name]["ticks"] > 0){
                continue;
            }
            unset($this->flightTransitions[$name]);
            $this->completeFlightTransition($player, $name, $transition["origin"]);
        }
    }

    private function completeFlightTransition(Player $player, $name, $origin){
        $level = $this->getServer()->getLevelByName($this->universeName);
        if($level === null){
            $player->sendMessage(TextFormat::RED . "宇宙世界尚未就绪，飞行器已返还。");
            $this->giveAircraft($player);
            return;
        }
        if($this->isPlanetWorld($origin)){
            $planet = $this->registry->getByWorld($origin);
            if($planet === null){
                $this->giveAircraft($player);
                return;
            }
            $sphere = $planet["sphere"];
            $clearance = max(12, (int) $this->getConfig()->getNested("planet-worlds.return-clearance", 20));
            $radial = sqrt(($sphere["x"] * $sphere["x"]) + ($sphere["z"] * $sphere["z"]));
            $directionX = $radial > 0 ? $sphere["x"] / $radial : 1;
            $directionZ = $radial > 0 ? $sphere["z"] / $radial : 0;
            $distance = $sphere["radius"] + $clearance;
            $returnX = $sphere["x"] + ($directionX * $distance);
            $returnZ = $sphere["z"] + ($directionZ * $distance);
            $this->teleportAcrossWorld($player, new Position($returnX, $sphere["y"], $returnZ, $level));
            $player->setMotion(new Vector3(0, 0, 0));
            $this->setCooldown($name);
            $player->sendMessage(TextFormat::AQUA . "已返回宇宙中的 " . $origin . " 附近。");
            return;
        }
        $entry = $this->getUniverseEarthEntry();
        $this->teleportAcrossWorld($player, new Position($entry[0], $entry[1], $entry[2], $level));
        $this->setCooldown($name);
        $player->sendMessage(TextFormat::AQUA . "已突破大气层，进入宇宙。");
    }

    private function emitRocketParticles(Entity $entity){
        $level = $entity->getLevel();
        $baseY = $entity->y - 0.65;
        $offsetX = mt_rand(-12, 12) / 100;
        $offsetZ = mt_rand(-12, 12) / 100;
        $level->addParticle(new FlameParticle(new Vector3($entity->x + $offsetX, $baseY, $entity->z + $offsetZ)));
    }

    private function endFlight($name, $player = null, $refund = false){
        if(!isset($this->flights[$name])){
            return;
        }
        $entity = $this->flights[$name]["entity"];
        if($player instanceof Player && $player->getLinkedEntity() instanceof Entity){
            $player->setLinked(0, $player->getLinkedEntity());
        }
        if($entity instanceof Entity && !$entity->closed){
            $entity->close();
        }
        unset($this->flights[$name]);
        unset($this->inputDebugAt[$name]);
        if($refund && $player instanceof Player){
            $this->giveAircraft($player);
        }
    }

    private function giveAircraft(Player $player){
        $item = $this->createAircraftItem();
        $left = $player->getInventory()->addItem($item);
        foreach($left as $drop){
            $player->getLevel()->dropItem($player, $drop);
        }
    }

    private function createAircraftItem(){
        $item = Item::get((int) $this->getConfig()->getNested("aircraft.item-id", Item::SPAWN_EGG), (int) $this->getConfig()->getNested("aircraft.item-meta", 120), 1);
        $item->setCustomName((string) $this->getConfig()->getNested("aircraft.name", "§b星际飞行器刷怪蛋"));
        return $item;
    }

    private function registerCreativeAircraft(){
        $item = Item::get((int) $this->getConfig()->getNested("aircraft.item-id", Item::SPAWN_EGG), (int) $this->getConfig()->getNested("aircraft.item-meta", 120), 1);
        if(!Item::isCreativeItem($item)){
            Item::addCreativeItem($item);
        }
    }

    private function consumeAircraft(Player $player){
        foreach($player->getInventory()->getContents() as $slot => $item){
            if($this->isAircraftItem($item)){
                if($item->getCount() <= 1){
                    $player->getInventory()->setItem($slot, Item::get(Item::AIR, 0, 0));
                }else{
                    $item->setCount($item->getCount() - 1);
                    $player->getInventory()->setItem($slot, $item);
                }
                return true;
            }
        }
        return false;
    }

    private function isAircraftItem(Item $item){
        $id = (int) $this->getConfig()->getNested("aircraft.item-id", Item::SPAWN_EGG);
        $meta = (int) $this->getConfig()->getNested("aircraft.item-meta", 120);
        if($item->getId() !== $id || $item->getDamage() !== $meta){
            return false;
        }
        // 旧版核心在加入创造栏时会主动丢弃自定义名称，所以专用刷怪蛋只按 ID/特殊值识别。
        return $id === Item::SPAWN_EGG || ($item->hasCustomName() && $item->getCustomName() === (string) $this->getConfig()->getNested("aircraft.name", "§b星际飞行器刷怪蛋"));
    }

    public function onDamage(EntityDamageEvent $event){
        $entity = $event->getEntity();
        if(!($entity instanceof Player)){
            return;
        }
        if(isset($this->dimensionTransitions[strtolower($entity->getName())])){
            $event->setCancelled(true);
            return;
        }
        $world = $entity->getLevel()->getFolderName();
        if(($world === $this->universeName || $this->isPlanetWorld($world)) && ($event->getCause() === EntityDamageEvent::CAUSE_FALL || $event->getCause() === EntityDamageEvent::CAUSE_VOID)){
            $event->setCancelled(true);
        }
    }

    public function onJoin(PlayerJoinEvent $event){
        $player = $event->getPlayer();
        $world = $player->getLevel()->getFolderName();
        $name = strtolower($player->getName());
        $netherName = strtolower((string) $this->getConfig()->getNested("dimension-transfer.nether-world", "nether"));
        if(strtolower($world) === $netherName){
            $stored = $this->dimensionTransitionStore instanceof Config ? $this->dimensionTransitionStore->get($name) : null;
            $targetLevel = null;
            if(is_array($stored) && isset($stored["world"])){
                $this->getServer()->loadLevel((string) $stored["world"]);
                $targetLevel = $this->getServer()->getLevelByName((string) $stored["world"]);
            }
            if($targetLevel !== null){
                $target = new Position((float) $stored["x"], (float) $stored["y"], (float) $stored["z"], $targetLevel);
                $storedOriginalGamemode = isset($stored["originalGamemode"]) ? (int) $stored["originalGamemode"] : (int) $player->getGamemode();
                // If the server stopped during the temporary Creative pulse, the
                // player can be saved as Creative. Restore the persisted original
                // mode before rebuilding the relay state.
                if($player->getGamemode() !== $storedOriginalGamemode){
                    $snapshot = $this->snapshotPlayerInventory($player);
                    $this->setGamemodePreservingInventory($player, $storedOriginalGamemode, $snapshot);
                }
                $this->dimensionTransitions[$name] = [
                    "target" => $target,
                    "started" => microtime(true),
                    "retryAt" => 0.0,
                    "targetPrepared" => false,
                    "netherAttempted" => true,
                    "netherStarted" => microtime(true),
                    "enteredNether" => true,
                    "netherEnteredAt" => microtime(true),
                    "netherTerrainReady" => false,
                    "netherChunks" => [],
                    "netherWaitNotice" => false,
                    "serverReady" => true,
                    "netherTicks" => 0,
                    "netherClientSettleTicks" => 0,
                    "netherClientResyncPasses" => 0,
                    "sourceWorld" => (string) ($stored["sourceWorld"] ?? $this->universeName),
                    "sourceX" => (float) ($stored["sourceX"] ?? 0.0),
                    "sourceY" => (float) ($stored["sourceY"] ?? 64.0),
                    "sourceZ" => (float) ($stored["sourceZ"] ?? 0.0),
                    "sourceYaw" => (float) ($stored["sourceYaw"] ?? $player->yaw),
                    "sourcePitch" => (float) ($stored["sourcePitch"] ?? $player->pitch),
                    "earthTarget" => $this->isEarthWorldName($targetLevel->getFolderName()),
                    "revokeFlightInNether" => !$player->isCreative() && strtolower($targetLevel->getFolderName()) !== strtolower($this->universeName),
                    "relayFlightRevoked" => false,
                    "relayClientResetSent" => false,
                    "originalGamemode" => $storedOriginalGamemode,
                    "nativeResetStage" => "waiting",
                    "nativeResetTicks" => 0,
                    "nativeResetAttempts" => 0,
                    "nativeGroundTicks" => 0,
                    "nativeFallStartY" => null,
                    "nativeObservedFall" => false,
                    "nativeResetComplete" => false,
                    "nativeInventorySnapshot" => null
                ];
                if(!isset($stored["sourceX"], $stored["sourceY"], $stored["sourceZ"])){
                    unset(
                        $this->dimensionTransitions[$name]["sourceX"],
                        $this->dimensionTransitions[$name]["sourceY"],
                        $this->dimensionTransitions[$name]["sourceZ"]
                    );
                }
                // The player really rejoined inside Nether.  Let the normal
                // settling/target-population state machine perform the second
                // hop instead of immediately skipping its safety window.
            }else{
                // Recovery for transfers created by versions before the store existed.
                $earthName = (string) $this->getConfig()->getNested("earth.return-world", "earth");
                $this->getServer()->loadLevel($earthName);
                $targetLevel = $this->getServer()->getLevelByName($earthName);
                if($targetLevel !== null){
                    $surface = $this->getEarthSurfaceSpawn($targetLevel);
                    if($surface instanceof Position){ $this->teleportAcrossWorld($player, $surface); }
                }
                if($this->dimensionTransitionStore instanceof Config){
                    $this->dimensionTransitionStore->remove($name);
                    $this->dimensionTransitionStore->save();
                }
            }
            $world = $player->getLevel()->getFolderName();
            if(isset($this->dimensionTransitions[$name])){
                $player->sendMessage(TextFormat::YELLOW . "检测到上次维度中转中断，正在从 Nether 继续完成目标传送。");
            }else{
                $player->sendMessage(TextFormat::YELLOW . "检测到上次维度中转中断，已恢复到安全世界。");
            }
        }
        if($world === $this->universeName && $player->y < 32){
            // 修复旧版错误宇宙把玩家保存在 Y=0 附近留下的滞留位置。
            $entry = $this->getUniverseEarthEntry();
            $player->teleport(new Position($entry[0], $entry[1], $entry[2], $player->getLevel()));
        }elseif($this->isPlanetWorld($world)){
            $planet = $this->registry->getByWorld($player->getLevel()->getFolderName());
            if($planet === null){
                $this->returnToConfiguredWorld($player);
            }else{
                $px = (int) floor($player->x);
                $py = max(1, min(126, (int) floor($player->y)));
                $pz = (int) floor($player->z);
                $player->getLevel()->loadChunk($px >> 4, $pz >> 4, true);
                $player->getLevel()->populateChunk($px >> 4, $pz >> 4, true);
                // Never treat a high Y (e.g. ocean sea level 110) as invalid.
                // Rescue only if the saved body position is actually in solid blocks.
                $blocked = $this->isSolidForLanding($player->getLevel()->getBlockIdAt($px, $py, $pz))
                    || $this->isSolidForLanding($player->getLevel()->getBlockIdAt($px, min(127, $py + 1), $pz));
                if($blocked || $player->y < 1 || $player->y > 126){
                    $safe = $this->findPlanetArrivalPosition($player->getLevel(), $planet, 8, 8);
                    $player->teleport($safe);
                    $this->queuePlanetLandingGuard($player, $safe);
                }
                $this->resetFlightPosture($player, false);
                $this->sendPlanetReport($player, $planet);
            }
        }elseif(strtolower($world) === strtolower((string) $this->getConfig()->getNested("earth.return-world", "earth"))){
            // doFirstSpawn() restores native gamemode abilities immediately
            // after PlayerJoinEvent. Do not fight it with an extra flight reset.
            $this->sendEarthReport($player);
        }

        // Login packets can be sent before the first regular plugin tick.
        // Re-apply and resend the destination state now and for a short time.
        if($world === $this->universeName){
            $this->syncUniverseTime($player);
            $this->applyUniverseRules($player, 0);
            $this->queueWorldResync($player);
            $this->queueEarthChunkStream($player, 12);
            $this->showSpaceWalkTutorial($player);
        }elseif($this->isPlanetWorld($world)){
            $planet = $this->registry->getByWorld($world);
            if($planet !== null){
                $this->applyPlanetEnvironment($player->getLevel(), $planet);
                $this->queueWorldResync($player);
            }
        }elseif(strtolower($world) === strtolower((string) $this->getConfig()->getNested("earth.return-world", "earth"))){
            $this->queueWorldResync($player);
        }
    }

    public function onLevelLoad(LevelLoadEvent $event){
        $this->stabilizeBrokenWeather($event->getLevel());
    }

    private function stabilizeBrokenWeather($level){
        if(!(bool) $this->getConfig()->getNested("rules.suppress-core-lightning-crash", true)){
            return;
        }
        if(defined('pocketmine\\entity\\Animal::DATA_FLAG_NOTBABY')){
            return;
        }
        $weather = $level->getWeather();
        $weather->setCanCalculate(false);
        $weather->setWeather(0, 12000);
    }

    public function onQuit(PlayerQuitEvent $event){
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        if(isset($this->dimensionTransitions[$name])){
            $this->restoreTransitionGamemodeIfNeeded($player, $name);
            $target = $this->dimensionTransitions[$name]["target"];
            // The old core often refuses teleport() after disconnect. Keep the
            // persisted target so onJoin can recover the player instead.
            if($player->teleport($target) && $player->getLevel() === $target->getLevel()){
                if($this->dimensionTransitionStore instanceof Config){
                    $this->dimensionTransitionStore->remove($name);
                    $this->dimensionTransitionStore->save();
                }
            }
            unset($this->dimensionTransitions[$name]);
        }
        if(isset($this->flights[$name])){
            $this->endFlight($name, $player, true);
        }
        if(isset($this->flightTransitions[$name])){
            unset($this->flightTransitions[$name]);
            $this->giveAircraft($player);
            $this->returnToConfiguredWorld($player);
        }
        $this->leaveCustomRules($player);
        $this->clearSpaceFlightAssist($player);
        if(isset($this->flightPostureRestores[$name])){
            $player->setAllowFlight($player->isCreative() || (bool) $this->flightPostureRestores[$name]["allow"]);
            unset($this->flightPostureRestores[$name]);
        }
        unset($this->inputDebug[$name], $this->inputDebugAt[$name], $this->worldResyncs[$name], $this->chunkRefreshes[$name], $this->earthChunkStreams[$name], $this->dimensionTransitions[$name], $this->planetLandingGuards[$name], $this->clientArrivalFinalizers[$name]);
    }

    /** @priority HIGHEST */
    public function onPreCommand(PlayerCommandPreprocessEvent $event){
        $message = trim($event->getMessage());
        if((bool) $this->getConfig()->getNested("rules.hide-planets-from-lw", true) && preg_match('/^\/lw(?:\s|$)/i', $message)){
            $event->setCancelled(true);
            $names = [];
            foreach($this->getServer()->getLevels() as $level){
                if(!$this->isPlanetWorld($level->getFolderName())){
                    $names[] = $level->getFolderName();
                }
            }
            $event->getPlayer()->sendMessage("§6==== 地图列表 ====");
            $event->getPlayer()->sendMessage("§b" . implode(", ", $names));
            return;
        }
        if((bool) $this->getConfig()->getNested("rules.block-direct-planet-warp", true) && preg_match('/^\/w\s+(' . preg_quote($this->planetPrefix, '/') . '[a-z0-9]+)\s*$/i', $message) && !$event->getPlayer()->hasPermission("universe.admin")){
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage(TextFormat::RED . "星球只能从宇宙中的对应球体进入。");
        }
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        if($command->getName() === "命名星球" || strtolower($command->getName()) === "planetname"){
            if(!($sender instanceof Player)){
                $sender->sendMessage(TextFormat::RED . "该指令只能由游戏内玩家执行。");
                return true;
            }
            $world = $sender->getLevel()->getFolderName();
            if(!$this->isPlanetWorld($world)){
                $sender->sendMessage(TextFormat::RED . "只能在随机星球上使用 /命名星球 名字。");
                return true;
            }
            $newName = trim(implode(" ", $args));
            if($newName === ""){
                $sender->sendMessage(TextFormat::YELLOW . "用法：/命名星球 名字");
                return true;
            }
            $result = $this->registry->namePlanet($world, $newName, $sender->getName());
            if(empty($result["ok"])){
                if(($result["reason"] ?? "") === "owned"){
                    $sender->sendMessage(TextFormat::RED . "该星球已被 " . $result["owner"] . " 命名");
                }elseif(($result["reason"] ?? "") === "empty"){
                    $sender->sendMessage(TextFormat::RED . "星球名字不能为空。");
                }else{
                    $sender->sendMessage(TextFormat::RED . "未找到当前星球的登记资料。");
                }
                return true;
            }
            $this->getServer()->broadcastMessage(TextFormat::AQUA . $sender->getName() . " 将星球 " . $world . " 命名为“" . $result["planet"]["displayName"] . "”");
            return true;
        }
        if(strtolower($command->getName()) !== "universe"){
            return false;
        }
        $sub = strtolower($args[0] ?? "help");
        if($sub === "help"){
            $sender->sendMessage("§b/universe aircraft [玩家] §7- 给予飞行器");
            $sender->sendMessage("§b/universe tp [世界名] §7- 跨世界传送，省略世界名则进入宇宙（OP）");
            $sender->sendMessage("§b/universe return §7- 返回配置世界");
            $sender->sendMessage("§b/universe worlds §7- 查看隐藏的星球世界（OP）");
            $sender->sendMessage("§b/universe inputdebug §7- 开关飞行器 motY 输入输出（OP）");
            $sender->sendMessage("§b/universe refresh §7- Universe: 重建 Earth 外壳并逐块重发；其他世界重同步缓存");
            return true;
        }
        if($sub === "refresh" && $sender instanceof Player){
            $level = $sender->getLevel();
            if($level->getFolderName() === $this->universeName){
                $center = $this->getUniverseEarthCenter();
                $this->queueUniverseLandmarkChunks($level, $center);
                if($this->areUniverseLandmarkChunksReady($level, $center)){
                    $this->ensureUniverseEarthShell($level, $center, true);
                    $this->repairUniverseLandmarkLighting($level, $center, true);
                    $this->queueEarthChunkStream($sender, 4);
                    $sender->sendMessage(TextFormat::AQUA . "已重建 Earth 低密度外壳，并开始按单 chunk 顺序重发给 0.14 客户端。 ");
                }else{
                    $sender->sendMessage(TextFormat::YELLOW . "Universe 地标仍在 population；插件会继续后台准备，稍后再执行 /universe refresh。 ");
                }
                $this->syncUniverseTime($sender);
                return true;
            }
            if(method_exists($level, "clearCache")){
                $level->clearCache(true);
            }
            $this->queueWorldResync($sender);
            $sender->sendMessage(TextFormat::AQUA . "已清理服务端 Level 缓存并重新同步当前世界。 ");
            return true;
        }
        if($sub === "return" && $sender instanceof Player){
            $world = $sender->getLevel()->getFolderName();
            if($world !== $this->universeName && !$this->isPlanetWorld($world)){
                $sender->sendMessage(TextFormat::RED . "这个指令只能在宇宙或星球世界使用。");
                return true;
            }
            $this->returnToConfiguredWorld($sender);
            return true;
        }
        if(!$sender->hasPermission("universe.admin")){
            $sender->sendMessage(TextFormat::RED . "你没有权限。");
            return true;
        }
        if($sub === "aircraft"){
            $target = $sender instanceof Player ? $sender : null;
            if(isset($args[1])){
                $target = $this->getServer()->getPlayer($args[1]);
            }
            if($target instanceof Player){
                $this->giveAircraft($target);
                $sender->sendMessage(TextFormat::GREEN . "已给予 " . $target->getName() . " 一个飞行器。");
            }else{
                $sender->sendMessage(TextFormat::RED . "找不到在线玩家。");
            }
            return true;
        }
        if($sub === "inputdebug"){
            if(!($sender instanceof Player)){
                $sender->sendMessage(TextFormat::RED . "请在游戏内执行此指令。");
                return true;
            }
            $name = strtolower($sender->getName());
            if(isset($this->inputDebug[$name])){
                unset($this->inputDebug[$name], $this->inputDebugAt[$name]);
                $sender->sendMessage(TextFormat::YELLOW . "PlayerInputPacket 调试输出已关闭。");
            }else{
                $this->inputDebug[$name] = true;
                $sender->sendMessage(TextFormat::GREEN . "PlayerInputPacket 调试输出已开启；坐入飞行器并按方向键查看 motY。");
            }
            return true;
        }
        if($sub === "tp"){
            if(!($sender instanceof Player)){
                $sender->sendMessage(TextFormat::RED . "这个指令需要玩家在游戏内执行。");
                return true;
            }
            $world = isset($args[1]) ? trim($args[1]) : $this->universeName;
            if($world === ""){
                $world = $this->universeName;
            }
            if($world === $this->universeName){
                if(!$this->ensureUniverseWorld()){
                    $sender->sendMessage(TextFormat::YELLOW . "宇宙地球区块仍在生成，请稍后再试。");
                    return true;
                }
            }elseif(!$this->getServer()->isLevelLoaded($world) && $this->getServer()->isLevelGenerated($world)){
                $this->getServer()->loadLevel($world);
            }
            $level = $this->getServer()->getLevelByName($world);
            if($level === null){
                $sender->sendMessage(TextFormat::RED . "世界 " . $world . " 不存在或无法加载。");
                return true;
            }
            if($world === $this->universeName){
                $entry = $this->getUniverseEarthEntry();
                $ok = $this->teleportAcrossWorld($sender, new Position($entry[0], $entry[1], $entry[2], $level));
            }else{
                $target = $this->isEarthWorldName($world) ? $this->getEarthSurfaceSpawn($level) : $level->getSafeSpawn();
                if(!($target instanceof Position)){
                    $sender->sendMessage(TextFormat::YELLOW . "目标世界地表出生点仍在生成，请稍后再试。");
                    return true;
                }
                $ok = $this->teleportAcrossWorld($sender, $target);
            }
            if(!$ok){
                return true;
            }
            if(isset($this->dimensionTransitions[strtolower($sender->getName())])){
                $this->setCooldown(strtolower($sender->getName()));
                $sender->sendMessage(TextFormat::YELLOW . "正在通过 Nether 中转到世界 " . $level->getFolderName() . "……");
                return true;
            }
            if($this->isPlanetWorld($world)){
                $this->resetFlightPosture($sender, false);
                $planet = $this->registry->getByWorld($world);
                if($planet !== null){
                    $this->sendPlanetReport($sender, $planet);
                }
            }elseif(strtolower($world) === strtolower((string) $this->getConfig()->getNested("earth.return-world", "earth"))){
                $restoreFlight = $this->leaveCustomRules($sender, true);
                $this->queueEarthFlightPostureReset($sender, $restoreFlight);
                $this->sendEarthReport($sender);
            }
            $this->setCooldown(strtolower($sender->getName()));
            $sender->sendMessage(TextFormat::GREEN . "已传送到世界 " . $level->getFolderName() . "。");
            return true;
        }
        if($sub === "worlds"){
            $loaded = $this->getLoadedPlanetNames();
            $archived = $this->getArchivedPlanetNames();
            $sender->sendMessage("§6已加载星球(" . count($loaded) . "): §b" . (count($loaded) ? implode(", ", $loaded) : "无"));
            $sender->sendMessage("§6已归档星球(" . count($archived) . "): §7" . (count($archived) ? implode(", ", array_slice($archived, 0, 20)) : "无"));
            if(count($archived) > 20){
                $sender->sendMessage("§7……另有 " . (count($archived) - 20) . " 个");
            }
            return true;
        }
        return true;
    }

    private function tickWorldLifecycle(){
        $delay = max(10, (int) $this->getConfig()->getNested("planet-worlds.unload-delay-seconds", 60));
        foreach($this->getLoadedPlanetNames() as $world){
            $level = $this->getServer()->getLevelByName($world);
            if($level === null || count($level->getPlayers()) > 0){
                unset($this->unloadAt[$world]);
                continue;
            }
            if(!isset($this->unloadAt[$world])){
                $this->unloadAt[$world] = time() + $delay;
            }elseif(time() >= $this->unloadAt[$world]){
                $this->unloadAndArchive($world, false);
            }
        }
    }

    private function unloadOldestEmptyPlanet(){
        foreach($this->getLoadedPlanetNames() as $world){
            $level = $this->getServer()->getLevelByName($world);
            if($level !== null && count($level->getPlayers()) === 0){
                $this->unloadAndArchive($world, false);
                return;
            }
        }
    }

    private function unloadAndArchive($world, $force){
        if(!$this->isPlanetWorld($world)){
            return false;
        }
        $level = $this->getServer()->getLevelByName($world);
        if($level !== null){
            if(count($level->getPlayers()) > 0 && !$force){
                return false;
            }
            $level->save(true);
            if(!$this->getServer()->unloadLevel($level, $force)){
                return false;
            }
        }
        unset($this->unloadAt[$world]);
        return $this->archiveWorldDirectory($world);
    }

    private function archiveStrandedPlanetWorlds(){
        $worldsPath = $this->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR;
        if(!is_dir($worldsPath)){
            return;
        }
        foreach(scandir($worldsPath) as $name){
            if($this->isPlanetWorld($name) && is_dir($worldsPath . $name)){
                $this->archiveWorldDirectory($name);
            }
        }
    }

    private function archiveWorldDirectory($world){
        if(!$this->isPlanetWorld($world)){
            return false;
        }
        $source = $this->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $world;
        $target = $this->archivePath . $world;
        if(!is_dir($source)){
            return true;
        }
        if(file_exists($target)){
            $this->getLogger()->warning("无法归档 $world：目标目录已存在。");
            return false;
        }
        return @rename($source, $target);
    }

    private function restoreArchivedWorld($world){
        if(!$this->isPlanetWorld($world)){
            return false;
        }
        $source = $this->archivePath . $world;
        $target = $this->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $world;
        if(!is_dir($source)){
            return false;
        }
        if(file_exists($target)){
            return true;
        }
        return @rename($source, $target);
    }

    private function getLoadedPlanetNames(){
        $names = [];
        foreach($this->getServer()->getLevels() as $level){
            if($this->isPlanetWorld($level->getFolderName())){
                $names[] = $level->getFolderName();
            }
        }
        return $names;
    }

    private function getArchivedPlanetNames(){
        if(!is_dir($this->archivePath)){
            return [];
        }
        $names = [];
        foreach(scandir($this->archivePath) as $name){
            if($this->isPlanetWorld($name) && is_dir($this->archivePath . $name)){
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    private function isPlanetWorld($name){
        return is_string($name) && preg_match('/^' . preg_quote($this->planetPrefix, '/') . '[a-z0-9]+$/i', $name) === 1;
    }

    private function isUniverseRenderLayoutCurrent($marker, array $center){
        if(!is_file($marker)){ return false; }
        $decoded = json_decode((string) @file_get_contents($marker), true);
        if(!is_array($decoded) || (int) ($decoded["version"] ?? 0) !== 2 || !isset($decoded["center"]) || !is_array($decoded["center"])){
            return false;
        }
        $saved = $decoded["center"];
        return isset($saved[0], $saved[1], $saved[2])
            && (int) $saved[0] === (int) $center[0]
            && (int) $saved[1] === (int) $center[1]
            && (int) $saved[2] === (int) $center[2];
    }

    private function getUniverseEarthCenter(){
        return $this->vectorConfig("coordinate-isolation.universe-earth-center", [-7992, 64, -7992]);
    }

    private function getUniverseEarthEntry(){
        $center = $this->getUniverseEarthCenter();
        $offset = $this->vectorConfig("coordinate-isolation.universe-entry-offset", [0, 0, 40]);
        return [$center[0] + $offset[0], $center[1] + $offset[1], $center[2] + $offset[2]];
    }

    private function getEarthSurfaceSpawn($level){
        if($level === null){ return null; }
        $xz = (array) $this->getConfig()->getNested("coordinate-isolation.earth-world-spawn-xz", [8200, 8200]);
        $baseX = isset($xz[0]) ? (int) floor($xz[0]) : 8200;
        $baseZ = isset($xz[1]) ? (int) floor($xz[1]) : 8200;
        $offsets = [[0,0],[1,0],[-1,0],[0,1],[0,-1],[2,0],[-2,0],[0,2],[0,-2],[3,0],[-3,0],[0,3],[0,-3],[4,0],[-4,0],[0,4],[0,-4],[4,4],[-4,4],[4,-4],[-4,-4],[6,0],[-6,0],[0,6],[0,-6]];
        foreach($offsets as $offset){
            $x = $baseX + $offset[0];
            $z = $baseZ + $offset[1];
            $chunkX = $x >> 4;
            $chunkZ = $z >> 4;
            $level->loadChunk($chunkX, $chunkZ, true);
            $level->populateChunk($chunkX, $chunkZ, true);
            if(method_exists($level, "isChunkPopulated") && !$level->isChunkPopulated($chunkX, $chunkZ)){
                continue;
            }
            for($y = 126; $y >= 2; --$y){
                $id = (int) $level->getBlockIdAt($x, $y, $z);
                if($this->isEarthSpawnPassable($id)){
                    continue;
                }
                // Water surface is still a surface: spawn one block above it
                // rather than drilling down to the seabed. Lava is rejected.
                if($id === 10 || $id === 11){
                    break;
                }
                $feetY = $y + 1;
                if($feetY > 126){
                    break;
                }
                // Default spawn must mean the top surface, not PocketMine's
                // nearest "safe" cavity. Clear only the two body blocks.
                $level->setBlock(new Vector3($x, $feetY, $z), Block::get(Block::AIR), true, false);
                if($feetY + 1 <= 127){
                    $level->setBlock(new Vector3($x, $feetY + 1, $z), Block::get(Block::AIR), true, false);
                }
                return new Position($x + 0.5, (float) $feetY, $z + 0.5, $level);
            }
        }
        return null;
    }

    private function isEarthSpawnPassable($id){
        // Air + common plants/snow layer in MCPE 0.14. They should not be
        // mistaken for terrain height when selecting the top surface.
        return in_array((int) $id, [0, 31, 32, 37, 38, 39, 40, 78], true);
    }

    private function vectorConfig($key, array $default){
        $value = (array) $this->getConfig()->getNested($key, []);
        if(isset($value["x"], $value["y"], $value["z"])){
            return [(float) $value["x"], (float) $value["y"], (float) $value["z"]];
        }
        if(isset($value[0], $value[1], $value[2])){
            return [(float) $value[0], (float) $value[1], (float) $value[2]];
        }
        return $default;
    }

    private function isCoolingDown($name){
        return isset($this->cooldowns[$name]) && $this->cooldowns[$name] > microtime(true);
    }

    private function setCooldown($name){
        $this->cooldowns[$name] = microtime(true) + 3.0;
    }
}
