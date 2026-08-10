<?php

namespace UniverseAdventure\generator;

use UniverseAdventure\world\EarthAsset;
use UniverseAdventure\world\UniverseLayout;
use pocketmine\level\ChunkManager;
use pocketmine\level\generator\Generator;
use pocketmine\math\Vector3;
use pocketmine\utils\Random;

class UniverseGenerator extends Generator{
    private $level;
    private $seed = 0;
    private $settings = [];
    private $layout = [];
    /** @var EarthAsset|null */
    private $earthAsset = null;
    private $earthShellMask = null;
    private $earthShellThickness = 1;
    private $earthCenter = [0, 64, 0];

    public function __construct(array $options = []){
        if(isset($options["preset"]) && is_string($options["preset"])){
            $decoded = json_decode($options["preset"], true);
            $this->settings = is_array($decoded) ? $decoded : [];
        }else{
            // SCAXE async generation workers pass getSettings() back here.
            $this->settings = $options;
        }
        $this->layout = $this->settings["layout"] ?? [];
        $this->earthCenter = $this->settings["earthCenter"] ?? [0, 64, 0];
        $this->earthShellThickness = max(1, min(8, (int) ($this->settings["earthShellThickness"] ?? 1)));
        $this->earthAsset = EarthAsset::load((string) ($this->settings["earthAsset"] ?? ""));
        if($this->earthAsset instanceof EarthAsset){
            $this->earthShellMask = $this->earthAsset->getShellMask($this->earthShellThickness);
        }
    }

    public function init(ChunkManager $level, Random $random){
        $this->level = $level;
        $this->seed = (int) $level->getSeed();
    }

    public function generateChunk($chunkX, $chunkZ){
        $chunk = $this->level->getChunk($chunkX, $chunkZ);
        $biome = (int) ($this->settings["biomeId"] ?? 1);
        $color = $this->settings["biomeColor"] ?? [24, 24, 40];
        for($x = 0; $x < 16; ++$x){
            for($z = 0; $z < 16; ++$z){
                $chunk->setBiomeId($x, $z, $biome);
                $chunk->setBiomeColor($x, $z, (int) $color[0], (int) $color[1], (int) $color[2]);
            }
        }

        $this->renderEarth($chunk, $chunkX, $chunkZ);

        $baseX = $chunkX << 4;
        $baseZ = $chunkZ << 4;
        foreach(UniverseLayout::spheresForChunk($this->seed, $chunkX, $chunkZ, $this->layout) as $sphere){
            $r = $sphere["radius"];
            $r2 = $r * $r;
            $minY = max(0, $sphere["y"] - $r);
            $maxY = min(127, $sphere["y"] + $r);
            for($lx = 0; $lx < 16; ++$lx){
                $dx = ($baseX + $lx) - $sphere["x"];
                if(($dx * $dx) > $r2){
                    continue;
                }
                for($lz = 0; $lz < 16; ++$lz){
                    $dz = ($baseZ + $lz) - $sphere["z"];
                    $horizontal = ($dx * $dx) + ($dz * $dz);
                    if($horizontal > $r2){
                        continue;
                    }
                    for($y = $minY; $y <= $maxY; ++$y){
                        $dy = $y - $sphere["y"];
                        if($horizontal + ($dy * $dy) <= $r2){
                            $chunk->setBlockId($lx, $y, $lz, $sphere["block"]["id"]);
                            $chunk->setBlockData($lx, $y, $lz, $sphere["block"]["meta"]);
                        }
                    }
                }
            }
        }
    }

    public function populateChunk($chunkX, $chunkZ){}

    public function getSettings(){
        return $this->settings;
    }

    public function getName(){
        return "Universe";
    }

    public function getSpawn(){
        $entry = $this->settings["entry"] ?? [0, 64, 40];
        return new Vector3((float) $entry[0], (float) $entry[1], (float) $entry[2]);
    }

    /**
     * Render only the visible Earth shell. The 0.14 client reliably renders
     * ordinary small planets, but the uploaded real save proves that the old
     * solid 64^3 Earth reaches >10k blocks in a single chunk and a whole chunk
     * mesh can disappear. Three backing layers preserve the exact exterior
     * while making Earth chunks comparable in density to the working planets.
     */
    private function renderEarth($chunk, $chunkX, $chunkZ){
        if(!($this->earthAsset instanceof EarthAsset) || !is_string($this->earthShellMask)){
            return;
        }
        list($width, $height, $length) = $this->earthAsset->getSize();
        $originX = (int) $this->earthCenter[0] - (int) floor($width / 2);
        $originY = (int) $this->earthCenter[1] - (int) floor($height / 2);
        $originZ = (int) $this->earthCenter[2] - (int) floor($length / 2);
        $baseX = $chunkX << 4;
        $baseZ = $chunkZ << 4;
        if($baseX > $originX + $width - 1 || $baseX + 15 < $originX || $baseZ > $originZ + $length - 1 || $baseZ + 15 < $originZ){
            return;
        }
        for($lx = 0; $lx < 16; ++$lx){
            $assetX = ($baseX + $lx) - $originX;
            if($assetX < 0 || $assetX >= $width){ continue; }
            for($lz = 0; $lz < 16; ++$lz){
                $assetZ = ($baseZ + $lz) - $originZ;
                if($assetZ < 0 || $assetZ >= $length){ continue; }
                for($assetY = 0; $assetY < $height; ++$assetY){
                    $worldY = $originY + $assetY;
                    if($worldY < 0 || $worldY >= 128){ continue; }
                    if(!$this->earthAsset->isShellBlock($assetX, $assetY, $assetZ, $this->earthShellThickness, $this->earthShellMask)){
                        continue;
                    }
                    $id = $this->earthAsset->getBlockId($assetX, $assetY, $assetZ);
                    $chunk->setBlockId($lx, $worldY, $lz, $id);
                    $chunk->setBlockData($lx, $worldY, $lz, $this->earthAsset->getBlockData($assetX, $assetY, $assetZ));
                }
            }
        }
    }
}
