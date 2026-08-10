<?php

namespace UniverseAdventure\world;

use pocketmine\utils\Config;

class PlanetRegistry{
    private $config;
    private $data;
    private $prefix;
    private $surfaceDepth;
    private $climate;
    private $woolShellDepth;

    public function __construct($path, $prefix = "planet", $surfaceDepth = 3, array $climate = [], $woolShellDepth = 3){
        $this->config = new Config($path, Config::YAML, ["counters" => [], "planets" => []]);
        $this->data = $this->config->getAll();
        $this->data["counters"] = $this->data["counters"] ?? [];
        $this->data["planets"] = $this->data["planets"] ?? [];
        $this->prefix = preg_replace('/[^a-z0-9]/', '', strtolower($prefix));
        $this->surfaceDepth = max(1, min(8, (int) $surfaceDepth));
        $this->climate = $climate;
        $this->woolShellDepth = max(1, min(8, (int) $woolShellDepth));
        $migrated = false;
        foreach($this->data["planets"] as &$planet){
            if(!isset($planet["visual"]["climate"])){
                $planet["visual"] = $this->makeVisual((int) $planet["seed"], (array) $planet["sphere"]);
                $migrated = true;
            }elseif(!isset($planet["visual"]["gravity"])){
                $planet["visual"]["gravity"] = $this->makeGravity((int) $planet["seed"]);
                $migrated = true;
            }
        }
        unset($planet);
        if($migrated){
            $this->save();
        }
    }

    public function discover(array $sphere){
        $key = (string) $sphere["key"];
        if(isset($this->data["planets"][$key])){
            return $this->data["planets"][$key];
        }
        $blockName = $sphere["block"]["name"] !== "" ? $sphere["block"]["name"] : "block" . $sphere["block"]["id"];
        $counter = ((int) ($this->data["counters"][$blockName] ?? 0)) + 1;
        $this->data["counters"][$blockName] = $counter;
        $world = $this->prefix . $blockName . $counter;
        $seed = (int) $sphere["seed"];
        $visual = $this->makeVisual($seed, $sphere);
        $planet = [
            "key" => $key,
            "world" => $world,
            "seed" => $seed,
            "block" => $sphere["block"],
            "sphere" => [
                "x" => (int) $sphere["x"], "y" => (int) $sphere["y"], "z" => (int) $sphere["z"], "radius" => (int) $sphere["radius"]
            ],
            "visual" => $visual,
            "ores" => $this->makeOres($seed),
            "created" => time()
        ];
        $this->data["planets"][$key] = $planet;
        $this->save();
        return $planet;
    }

    public function getByWorld($world){
        foreach($this->data["planets"] as $planet){
            if(strtolower($planet["world"]) === strtolower($world)){
                return $planet;
            }
        }
        return null;
    }

    public function getAll(){
        return $this->data["planets"];
    }

    public function getGeneratorSettings(array $planet){
        return [
            "planetId" => $planet["key"],
            "surface" => [(int) $planet["block"]["id"], (int) $planet["block"]["meta"]],
            "surfaceDepth" => $this->surfaceDepth,
            "biomes" => $planet["visual"]["biomes"],
            "biomeColor" => $planet["visual"]["biomeColor"],
            "climate" => $planet["visual"]["climate"],
            "seaLevel" => (int) $planet["visual"]["seaLevel"],
            "frozenOcean" => !empty($planet["visual"]["frozenOcean"]),
            "woolPlanet" => (int) $planet["block"]["id"] === 35,
            "woolColor" => (int) $planet["block"]["meta"],
            "woolShellDepth" => $this->woolShellDepth,
            "ores" => $planet["ores"]
        ];
    }

    public function namePlanet($world, $newName, $playerName){
        $newName = trim(preg_replace('/\xC2\xA7./u', '', (string) $newName));
        if(function_exists("mb_substr")){
            $newName = mb_substr($newName, 0, 24, "UTF-8");
        }else{
            $newName = substr($newName, 0, 48);
        }
        if($newName === ""){
            return ["ok" => false, "reason" => "empty"];
        }
        foreach($this->data["planets"] as &$planet){
            if(strtolower($planet["world"]) !== strtolower($world)){
                continue;
            }
            $owner = (string) ($planet["nameOwner"] ?? "");
            if($owner !== "" && strtolower($owner) !== strtolower($playerName)){
                unset($planet);
                return ["ok" => false, "reason" => "owned", "owner" => $owner];
            }
            if($owner === ""){
                $planet["nameOwner"] = (string) $playerName;
            }
            $planet["displayName"] = $newName;
            $planet["namedAt"] = time();
            $result = $planet;
            unset($planet);
            $this->save();
            return ["ok" => true, "planet" => $result];
        }
        unset($planet);
        return ["ok" => false, "reason" => "not_found"];
    }

    public function save(){
        $this->config->setAll($this->data);
        $this->config->save();
    }

    private function makeVisual($seed, array $sphere){
        $distance = sqrt(($sphere["x"] * $sphere["x"]) + ($sphere["z"] * $sphere["z"]));
        $baseNormal = (int) ($this->climate["normal-percent"] ?? 50);
        $nearBoostMax = (int) ($this->climate["near-earth-normal-boost"] ?? 20);
        $nearRange = max(128, (int) ($this->climate["near-earth-range"] ?? 1024));
        $nearBoost = (int) round($nearBoostMax * max(0, 1 - min($distance, $nearRange) / $nearRange));
        $normalChance = max(0, min(90, $baseNormal + $nearBoost));
        $remaining = 100 - $normalChance;
        $aridChance = (int) round($remaining * 0.60); // 远处回落为 30%，海洋为 20%。
        $climateRoll = $this->hash($seed, "climate") % 100;
        if($climateRoll < $normalChance){
            $climate = "normal";
        }elseif($climateRoll < $normalChance + $aridChance){
            $climate = "arid";
        }else{
            $climate = "ocean";
        }

        $surfaceId = (int) $sphere["block"]["id"];
        $surfaceMeta = (int) $sphere["block"]["meta"];
        $frozenOcean = $surfaceId === 80;
        if($frozenOcean){
            $profiles = [[[12, 13, 30], [180, 215, 228]]];
        }elseif($surfaceId === 110){
            $profiles = [[[14, 15], [108, 78, 132]], [[6, 21], [74, 116, 82]]];
        }elseif($surfaceId === 3 && $surfaceMeta === 2){
            $profiles = [[[5, 19, 32], [94, 112, 74]], [[6, 21, 22], [62, 126, 74]]];
        }elseif($climate === "arid"){
            $profiles = [[[2, 17, 35], [198, 154, 76]], [[36, 37, 38], [164, 88, 58]]];
        }elseif($climate === "ocean"){
            $profiles = [[[0, 24], [46, 108, 142]], [[6, 21, 22], [48, 124, 86]]];
        }else{
            $profiles = [
                [[1, 4, 5], [92, 164, 110]],
                [[6, 21, 22], [50, 145, 88]],
                [[3, 20, 34], [120, 102, 158]],
                [[12, 13, 30], [174, 208, 218]]
            ];
        }
        $profile = $profiles[$this->hash($seed, "profile") % count($profiles)];
        if($surfaceId === 35){
            $woolColors = [
                [221, 221, 221], [219, 125, 62], [179, 80, 188], [107, 138, 201],
                [177, 166, 39], [65, 174, 56], [208, 132, 153], [64, 64, 64],
                [154, 161, 161], [46, 110, 137], [126, 61, 181], [46, 56, 141],
                [79, 50, 31], [53, 70, 27], [150, 52, 48], [25, 22, 22]
            ];
            $profile = [[1, 4, 5], $woolColors[$surfaceMeta & 0x0f]];
        }
        $jitter = function($value, $salt) use ($seed){
            $delta = (($this->hash($seed, "color:" . $salt) % 31) - 15);
            return max(0, min(255, $value + $delta));
        };
        $timeRoll = $this->hash($seed, "time") % 100;
        $timeMode = $timeRoll < 60 ? "cycle" : ($timeRoll < 80 ? "day" : "night");
        $time = $timeMode === "day" ? 6000 : ($timeMode === "night" ? 18000 : ($this->hash($seed, "startTime") % 24000));
        $seaLevel = $climate === "ocean" ? 80 + ($this->hash($seed, "sea") % 41) : ($climate === "arid" ? 0 : 63);
        return [
            "biomes" => $profile[0],
            "biomeColor" => $surfaceId === 35 ? $profile[1] : [$jitter($profile[1][0], 1), $jitter($profile[1][1], 6), $jitter($profile[1][2], 11)],
            "climate" => $climate,
            "seaLevel" => $seaLevel,
            "frozenOcean" => $frozenOcean,
            "timeMode" => $timeMode,
            "time" => $time,
            "weather" => $climate === "normal" ? "cycle" : ($climate === "ocean" ? "rain" : "clear"),
            "nightVision" => ($this->hash($seed, "light") % 100) < 45,
            "gravity" => $this->makeGravity($seed)
        ];
    }

    private function makeGravity($seed){
        $roll = $this->hash($seed, "gravity") % 100;
        if($roll < 40){
            return ["type" => "normal"];
        }
        if($roll < 70){
            $gravity = ["type" => "low", "jump" => $this->hash($seed, "gravity:jump") % 2];
            if(($this->hash($seed, "gravity:speed") % 100) < 55){
                $gravity["speed"] = 0;
            }
            return $gravity;
        }
        return ["type" => "high", "slowness" => ($this->hash($seed, "gravity:slow") % 100) < 25 ? 1 : 0];
    }

    private function hash($seed, $salt){
        $value = crc32((string) $seed . ":" . $salt);
        if($value < 0){ $value += 4294967296; }
        return (int) ($value & 0x7fffffff);
    }

    private function makeOres($seed){
        $definitions = [
            ["id" => 16, "meta" => 0, "baseCount" => 14, "baseSize" => 12, "maxY" => 110],
            ["id" => 15, "meta" => 0, "baseCount" => 10, "baseSize" => 8, "maxY" => 70],
            ["id" => 14, "meta" => 0, "baseCount" => 2, "baseSize" => 6, "maxY" => 38],
            ["id" => 21, "meta" => 0, "baseCount" => 2, "baseSize" => 5, "maxY" => 34],
            ["id" => 73, "meta" => 0, "baseCount" => 5, "baseSize" => 6, "maxY" => 22],
            ["id" => 56, "meta" => 0, "baseCount" => 1, "baseSize" => 5, "maxY" => 18],
            ["id" => 129, "meta" => 0, "baseCount" => 1, "baseSize" => 4, "maxY" => 36],
            ["id" => 153, "meta" => 0, "baseCount" => 5, "baseSize" => 8, "maxY" => 96]
        ];
        $ores = [];
        foreach($definitions as $index => $definition){
            $h = crc32($seed . ":ore:" . $index);
            if($h < 0){ $h += 4294967296; }
            // Every ore rolls independently across a deliberately wide range.
            // Rare ores can therefore become a planet's dominant resource.
            $count = 1 + ($h % 40);
            $size = 2 + (($h >> 8) % 15);
            $ores[] = [
                "id" => $definition["id"],
                "meta" => $definition["meta"],
                "count" => $count,
                "size" => $size,
                "minY" => 1 + (($h >> 16) % 5),
                "maxY" => max(8, min(126, $definition["maxY"] + (($h >> 20) % 15) - 7))
            ];
        }
        return $ores;
    }
}
