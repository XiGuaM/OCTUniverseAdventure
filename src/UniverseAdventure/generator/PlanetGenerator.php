<?php

namespace UniverseAdventure\generator;

use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\normal\Normal;
use pocketmine\level\generator\object\OreType;
use pocketmine\level\generator\populator\Ore;
use pocketmine\math\Vector3;
use pocketmine\utils\Random;

class PlanetGenerator extends Generator{
    private $level;
    private $random;
    private $normal;
    private $ore;
    private $settings = [];

    public function __construct(array $options = []){
        if(isset($options["preset"]) && is_string($options["preset"])){
            $decoded = json_decode($options["preset"], true);
            $this->settings = is_array($decoded) ? $decoded : [];
        }else{
            // SCAXE 的异步生成线程会把 getSettings() 的结果直接传回构造器。
            $this->settings = $options;
        }
        $this->normal = new Normal([]);
    }

    public function init(ChunkManager $level, Random $random){
        $this->level = $level;
        $this->random = $random;
        $this->normal->init($level, $random);
        $this->ore = new Ore();
        $types = [];
        foreach(($this->settings["ores"] ?? []) as $ore){
            $types[] = new OreType(
                Block::get((int) $ore["id"], (int) ($ore["meta"] ?? 0)),
                (int) $ore["count"],
                (int) $ore["size"],
                (int) $ore["minY"],
                (int) $ore["maxY"]
            );
        }
        $this->ore->setOreTypes($types);
    }

    public function generateChunk($chunkX, $chunkZ){
        $this->normal->generateChunk($chunkX, $chunkZ);
        $chunk = $this->level->getChunk($chunkX, $chunkZ);
        $surface = $this->settings["surface"] ?? [1, 0];
        $depth = max(1, min(8, (int) ($this->settings["surfaceDepth"] ?? 3)));
        $biomes = $this->settings["biomes"] ?? [1];
        $color = $this->settings["biomeColor"] ?? [80, 160, 80];
        $climate = (string) ($this->settings["climate"] ?? "normal");
        $seaLevel = max(0, min(120, (int) ($this->settings["seaLevel"] ?? 63)));
        $frozenOcean = !empty($this->settings["frozenOcean"]);
        $seed = (int) $this->level->getSeed();

        // Wool planets retain the vanilla terrain shape AND the biome IDs
        // selected by Normal::generateChunk().  This matters because the
        // original Normal::populateChunk() reads the centre biome ID and then
        // runs that biome's own populators (ForestBiome -> Tree, Taiga -> Tree,
        // Jungle -> Tree, etc.).  Older versions overwrote every wool chunk
        // with one fixed biome ID here, which silently disabled those tree
        // populators.  We only override the visual biome colour; the original
        // biome identity stays intact.  Population runs first in
        // populateChunk(), then logs/leaves are converted to wool.
        if(!empty($this->settings["woolPlanet"])){
            for($x = 0; $x < 16; ++$x){
                for($z = 0; $z < 16; ++$z){
                    $chunk->setBiomeColor($x, $z, (int) $color[0], (int) $color[1], (int) $color[2]);
                }
            }
            // Do not wait for population before creating the visible wool
            // shell. Genisys can save and send a generated chunk while its
            // TerrainPopulated flag is still false; the old order therefore
            // exposed ordinary grass/stone across large parts of a wool
            // planet. Population below still runs normally and performs a
            // second conversion so ores and decorations are handled as well.
            $this->convertChunkToWoolShell($chunkX, $chunkZ);
            return;
        }

        for($x = 0; $x < 16; ++$x){
            for($z = 0; $z < 16; ++$z){
                $worldX = ($chunkX << 4) + $x;
                $worldZ = ($chunkZ << 4) + $z;
                $regionHash = crc32($seed . ":" . ((int) floor($worldX / 48)) . ":" . ((int) floor($worldZ / 48)));
                if($regionHash < 0){
                    $regionHash += 4294967296;
                }
                $chunk->setBiomeId($x, $z, (int) $biomes[$regionHash % count($biomes)]);
                $chunk->setBiomeColor($x, $z, (int) $color[0], (int) $color[1], (int) $color[2]);

                $replaced = 0;
                $topSolid = -1;
                for($y = 127; $y > 0; --$y){
                    $id = $chunk->getBlockId($x, $y, $z);
                    if($id === Block::AIR || $id === Block::WATER || $id === Block::STILL_WATER || $id === Block::ICE){
                        continue;
                    }
                    if($id === Block::BEDROCK){
                        break;
                    }
                    if($topSolid < 0){
                        $topSolid = $y;
                    }
                    $chunk->setBlockId($x, $y, $z, (int) $surface[0]);
                    $chunk->setBlockData($x, $y, $z, (int) $surface[1]);
                    if(++$replaced >= $depth){
                        break;
                    }
                }

                if($climate === "arid"){
                    // 无雨星球完全移除海洋、湖泊和地下水。
                    for($y = 1; $y < 128; ++$y){
                        $id = $chunk->getBlockId($x, $y, $z);
                        if($id === Block::WATER || $id === Block::STILL_WATER || $id === Block::ICE){
                            $chunk->setBlockId($x, $y, $z, Block::AIR);
                            $chunk->setBlockData($x, $y, $z, 0);
                        }
                    }
                    continue;
                }

                if($climate === "ocean" && $topSolid >= 0 && $topSolid < $seaLevel){
                    // 海洋星球保留原版陆地噪声，只把水面提高到随机的 80～120。
                    for($y = $topSolid + 1; $y <= $seaLevel; ++$y){
                        $id = $chunk->getBlockId($x, $y, $z);
                        if($id === Block::AIR || $id === Block::WATER || $id === Block::STILL_WATER || $id === Block::ICE){
                            $chunk->setBlockId($x, $y, $z, Block::STILL_WATER);
                            $chunk->setBlockData($x, $y, $z, 0);
                        }
                    }
                }

                if($frozenOcean && $topSolid >= 0){
                    $waterTop = -1;
                    for($y = min(127, max(63, $seaLevel)); $y > $topSolid; --$y){
                        $id = $chunk->getBlockId($x, $y, $z);
                        if($id === Block::WATER || $id === Block::STILL_WATER){
                            $waterTop = $y;
                            break;
                        }
                    }
                    if($waterTop >= 0){
                        $iceHash = crc32($seed . ":ice:" . $worldX . ":" . $worldZ . ":" . $topSolid);
                        if($iceHash < 0){ $iceHash += 4294967296; }
                        // 厚度 2～10，并混入底部陆地高度，让冰层下缘随陆地噪声起伏。
                        $thickness = 2 + (($iceHash + $topSolid) % 9);
                        $bottom = max($topSolid + 1, $waterTop - $thickness + 1);
                        for($y = $waterTop; $y >= $bottom; --$y){
                            $id = $chunk->getBlockId($x, $y, $z);
                            if($id === Block::WATER || $id === Block::STILL_WATER){
                                $chunk->setBlockId($x, $y, $z, Block::ICE);
                                $chunk->setBlockData($x, $y, $z, 0);
                            }
                        }
                    }
                }
            }
        }
    }

    public function populateChunk($chunkX, $chunkZ){
        if(!empty($this->settings["woolPlanet"])){
            $this->normal->populateChunk($chunkX, $chunkZ);
            $this->random->setSeed(0x51f15e ^ ($chunkX << 8) ^ $chunkZ ^ $this->level->getSeed());
            $this->ore->populate($this->level, $chunkX, $chunkZ, $this->random);
            $this->convertChunkToWoolShell($chunkX, $chunkZ);

            // The supplied Normal generator does run biome populators, but old
            // Genisys async generation is allowed to mark neighbouring chunks
            // populated in an order where Tree/Cactus attempts can all fail.
            // After the terrain is in its final low-density wool-shell form, run
            // a deterministic chunk-local vegetation pass modelled on the same
            // biome densities. This never writes into a neighbouring chunk.
            $chunk = $this->level->getChunk($chunkX, $chunkZ);
            if($chunk !== null){
                self::decorateWoolVegetation($chunk, $chunkX, $chunkZ, (int) $this->level->getSeed(), false);
            }
            return;
        }
        $this->random->setSeed(0x51f15e ^ ($chunkX << 8) ^ $chunkZ ^ $this->level->getSeed());
        $this->ore->populate($this->level, $chunkX, $chunkZ, $this->random);
    }

    private function convertChunkToWoolShell($chunkX, $chunkZ){
        $chunk = $this->level->getChunk($chunkX, $chunkZ);
        $ores = [14 => true, 15 => true, 16 => true, 21 => true, 56 => true, 73 => true, 74 => true, 129 => true, 153 => true];
        $partial = [
            6, 26, 27, 28, 30, 31, 32, 37, 38, 39, 40, 50, 51, 53, 54, 55, 59,
            63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 75, 76, 77, 78, 83, 85, 90,
            92, 93, 94, 96, 101, 102, 104, 105, 106, 107, 108, 109, 111, 113, 114,
            115, 117, 118, 119, 120, 126, 127, 128, 131, 132, 134, 135, 136, 139,
            140, 141, 142, 143, 144, 145, 146, 147, 148, 149, 150, 151, 154, 156,
            157, 160, 163, 164, 167, 171, 175, 176, 177, 178, 180, 182, 183, 184,
            185, 186, 187, 193, 194, 195, 196, 197
        ];
        $partialLookup = array_fill_keys($partial, true);
        for($x = 0; $x < 16; ++$x){
            for($z = 0; $z < 16; ++$z){
                for($y = 0; $y < 128; ++$y){
                    $id = $chunk->getBlockId($x, $y, $z);
                    if($id === Block::AIR || isset($ores[$id])){
                        continue;
                    }
                    if(isset($partialLookup[$id])){
                        $chunk->setBlockId($x, $y, $z, Block::AIR);
                        $chunk->setBlockData($x, $y, $z, 0);
                        continue;
                    }
                    $depth = max(1, min(8, (int) ($this->settings["woolShellDepth"] ?? 3)));
                    $worldX = ($chunkX << 4) + $x;
                    $worldZ = ($chunkZ << 4) + $z;
                    if(!$this->isNearWoolSurface($worldX, $y, $worldZ, $depth, $partialLookup)){
                        // Hidden terrain remains the original vanilla block. The
                        // player still sees an entirely wool exterior, but MCPE
                        // 0.14 no longer receives chunks containing ten-thousand+
                        // wool blocks of varying metadata.
                        continue;
                    }
                    $meta = $chunk->getBlockData($x, $y, $z);
                    $wool = $this->nearestWoolForBlock($id, $meta);
                    $chunk->setBlockId($x, $y, $z, Block::WOOL);
                    $chunk->setBlockData($x, $y, $z, $wool);
                }
            }
        }
    }

    private function isNearWoolSurface($x, $y, $z, $depth, array $partialLookup){
        $dirs = [[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]];
        foreach($dirs as $dir){
            for($i = 1; $i <= $depth; ++$i){
                $ny = $y + ($dir[1] * $i);
                if($ny < 0 || $ny > 127){ return true; }
                $id = $this->level->getBlockIdAt($x + ($dir[0] * $i), $ny, $z + ($dir[2] * $i));
                if($id === Block::AIR || isset($partialLookup[$id])){
                    return true;
                }
            }
        }
        return false;
    }

    private function nearestWoolForBlock($id, $meta){
        // Trees on wool planets deliberately use two fixed colours requested
        // by the pack: logs -> brown wool (12), leaves -> lime/light-green wool
        // (5). Grass remains normal green wool (13), so tree canopies are easy
        // to distinguish from the ground.
        if($id === 18 || $id === 161){ return 5; }
        if($id === 2){ return 13; }
        if($id === 17 || $id === 162 || $id === 3 || $id === 5 || $id === 88){ return 12; }
        if($id === 1 || $id === 4 || $id === 13 || $id === 43 || $id === 44 || $id === 98){ return 7; }
        if($id === 8 || $id === 9){ return 11; }
        if($id === 79 || $id === 174 || $id === 20){ return 3; }
        if($id === 80){ return 0; }
        if($id === 81 || $id === 48){ return 13; }
        if($id === 12 || $id === 24 || $id === 121 || $id === 170){ return 4; }
        if($id === 7 || $id === 49 || $id === 90){ return 15; }
        if($id === 10 || $id === 11 || $id === 86 || $id === 91){ return 1; }
        if($id === 45 || $id === 87){ return 14; }
        if($id === 82){ return 8; }
        if($id === 89){ return 4; }
        if($id === 103){ return 5; }
        if($id === 110){ return 10; }
        if($id === 159){ return $meta & 0x0f; }
        if($id === 172){ return 1; }
        if($id === 173){ return 15; }
        if($id === 35){ return $meta & 0x0f; }
        // Unknown complete blocks become a neutral, close-looking light grey.
        return 8;
    }


    /**
     * Deterministic wool-world vegetation compatible with the supplied Normal
     * generator. Forest/jungle/taiga-like biomes get more trees, desert-like
     * biomes get cacti, while ordinary green or sandy surface chunks still have
     * a small fallback chance so legacy biome metadata cannot leave the planet
     * permanently barren. Everything stays inside one chunk to avoid the old
     * asynchronous cross-chunk population race.
     *
     * Trees are final wool blocks by design: trunk = brown (12), leaves = lime
     * (5). Cacti are green wool (13). The terrain shell underneath remains the
     * low-density rendering fix introduced in 0.6.3.
     */
    public static function decorateWoolVegetation($chunk, $chunkX, $chunkZ, $seed, $legacy = false){
        if($chunk === null){ return 0; }
        $biome = (int) $chunk->getBiomeId(7, 7);
        $treeBudget = self::woolTreeBudgetForBiome($biome);
        $cactusBudget = self::woolCactusBudgetForBiome($biome);

        // Versions <=0.6.3 overwrote biome IDs. Surface material is therefore
        // also used as a fallback signal. New plains remain sparse, but not
        // completely lifeless; yellow sand-like wool can always host cacti.
        if($legacy){
            $treeBudget = max($treeBudget, 2);
            $cactusBudget = max($cactusBudget, 3);
        }else{
            if($treeBudget <= 0){ $treeBudget = 1; }
            if($cactusBudget <= 0){ $cactusBudget = 2; }
        }

        $changed = 0;
        $treesPlaced = 0;
        $treeAttempts = max(4, $treeBudget * 4);
        for($attempt = 0; $attempt < $treeAttempts && $treesPlaced < $treeBudget; ++$attempt){
            $salt = $seed . ":woolveg:tree:" . ((int) $chunkX) . ":" . ((int) $chunkZ) . ":" . $attempt;
            $lx = 3 + (self::unsignedCrc32($salt . ":x") % 10);
            $lz = 3 + (self::unsignedCrc32($salt . ":z") % 10);
            $groundY = self::findWoolSurfaceY($chunk, $lx, $lz, 13);
            if($groundY < 1){ continue; }
            // Keep the low-density fallback sparse outside tree-heavy biomes.
            if(self::woolTreeBudgetForBiome($biome) <= 0 && !$legacy && (self::unsignedCrc32($salt . ":chance") % 100) >= 35){
                continue;
            }
            $height = 4 + (self::unsignedCrc32($salt . ":h") % 3);
            $placed = self::placeWoolTree($chunk, $lx, $groundY + 1, $lz, $height, $salt);
            if($placed > 0){
                $changed += $placed;
                ++$treesPlaced;
            }
        }

        $cactiPlaced = 0;
        $cactusAttempts = max(6, $cactusBudget * 5);
        for($attempt = 0; $attempt < $cactusAttempts && $cactiPlaced < $cactusBudget; ++$attempt){
            $salt = $seed . ":woolveg:cactus:" . ((int) $chunkX) . ":" . ((int) $chunkZ) . ":" . $attempt;
            $lx = 2 + (self::unsignedCrc32($salt . ":x") % 12);
            $lz = 2 + (self::unsignedCrc32($salt . ":z") % 12);
            $groundY = self::findWoolSurfaceY($chunk, $lx, $lz, 4);
            if($groundY < 1){ continue; }
            $height = 2 + (self::unsignedCrc32($salt . ":h") % 3);
            $placed = self::placeWoolCactus($chunk, $lx, $groundY + 1, $lz, $height);
            if($placed > 0){
                $changed += $placed;
                ++$cactiPlaced;
            }
        }

        if($changed > 0 && method_exists($chunk, "setChanged")){
            $chunk->setChanged();
        }
        return $changed;
    }

    private static function woolTreeBudgetForBiome($biome){
        // MCPE 0.14 biome IDs follow the classic numeric table. Values mirror
        // the supplied generator approximately: Forest=5, Jungle/Taiga dense,
        // Roofed Forest dense, Savanna/Mountains sparse.
        if(in_array($biome, [4, 18, 27, 28], true)){ return 5; }
        if(in_array($biome, [21, 22, 23], true)){ return 8; }
        if(in_array($biome, [5, 19, 30, 31, 32, 33], true)){ return 6; }
        if($biome === 29){ return 7; }
        if(in_array($biome, [3, 20, 34, 35, 36], true)){ return 1; }
        if($biome === 6){ return 2; }
        return 0;
    }

    private static function woolCactusBudgetForBiome($biome){
        // Desert, Desert Hills and Mesa-family biomes. Sandy surface fallback
        // below also covers legacy chunks whose biome ID was overwritten.
        return in_array($biome, [2, 17, 37, 38, 39], true) ? 4 : 0;
    }

    private static function findWoolSurfaceY($chunk, $x, $z, $meta){
        for($y = 126; $y >= 1; --$y){
            $id = $chunk->getBlockId($x, $y, $z);
            if($id === Block::AIR){ continue; }
            if($id === Block::WOOL && $chunk->getBlockData($x, $y, $z) === (int) $meta){
                return $y;
            }
            // The first non-air block is a different surface (water/ice/rock).
            return -1;
        }
        return -1;
    }

    private static function placeWoolTree($chunk, $x, $baseY, $z, $height, $salt){
        $topY = $baseY + $height - 1;
        if($baseY < 2 || $topY + 1 > 126){ return 0; }

        // Reserve trunk and crown before writing anything. Canopy radius 2 and
        // x/z candidates 3..12 guarantee no neighbour-chunk writes.
        for($y = $baseY; $y <= $topY; ++$y){
            if($chunk->getBlockId($x, $y, $z) !== Block::AIR){ return 0; }
        }
        for($y = $topY - 2; $y <= $topY + 1; ++$y){
            $radius = $y >= $topY ? 1 : 2;
            for($dx = -$radius; $dx <= $radius; ++$dx){
                for($dz = -$radius; $dz <= $radius; ++$dz){
                    if($dx === 0 && $dz === 0 && $y <= $topY){ continue; }
                    if($chunk->getBlockId($x + $dx, $y, $z + $dz) !== Block::AIR){ return 0; }
                }
            }
        }

        $changed = 0;
        for($y = $baseY; $y <= $topY; ++$y){
            $chunk->setBlockId($x, $y, $z, Block::WOOL);
            $chunk->setBlockData($x, $y, $z, 12);
            ++$changed;
        }
        for($y = $topY - 2; $y <= $topY + 1; ++$y){
            $radius = $y >= $topY ? 1 : 2;
            for($dx = -$radius; $dx <= $radius; ++$dx){
                for($dz = -$radius; $dz <= $radius; ++$dz){
                    if($dx === 0 && $dz === 0 && $y <= $topY){ continue; }
                    if($radius === 2 && abs($dx) === 2 && abs($dz) === 2 && (self::unsignedCrc32($salt . ":leaf:" . $y . ":" . $dx . ":" . $dz) & 1) === 0){
                        continue;
                    }
                    if($chunk->getBlockId($x + $dx, $y, $z + $dz) === Block::AIR){
                        $chunk->setBlockId($x + $dx, $y, $z + $dz, Block::WOOL);
                        $chunk->setBlockData($x + $dx, $y, $z + $dz, 5);
                        ++$changed;
                    }
                }
            }
        }
        return $changed;
    }

    private static function placeWoolCactus($chunk, $x, $baseY, $z, $height){
        if($baseY < 2 || $baseY + $height > 127){ return 0; }
        for($i = 0; $i < $height; ++$i){
            $y = $baseY + $i;
            if($chunk->getBlockId($x, $y, $z) !== Block::AIR){ return 0; }
            // Keep the familiar isolated cactus silhouette even though these are
            // final wool blocks and therefore do not need vanilla cactus physics.
            foreach([[1,0],[-1,0],[0,1],[0,-1]] as $dir){
                if($chunk->getBlockId($x + $dir[0], $y, $z + $dir[1]) !== Block::AIR){ return 0; }
            }
        }
        for($i = 0; $i < $height; ++$i){
            $chunk->setBlockId($x, $baseY + $i, $z, Block::WOOL);
            $chunk->setBlockData($x, $baseY + $i, $z, 13);
        }
        return $height;
    }

    private static function unsignedCrc32($value){
        $hash = crc32((string) $value);
        if($hash < 0){ $hash += 4294967296; }
        return $hash;
    }

    public function getSettings(){ return $this->settings; }
    public function getName(){ return "RandomPlanet"; }
    public function getSpawn(){ return new Vector3(8.5, 100, 8.5); }
}
