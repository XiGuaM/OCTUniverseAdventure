<?php

namespace UniverseAdventure\world;

/**
 * Tiny reader for the bundled UAE1 Earth voxel asset.
 *
 * 0.6.0 deliberately renders only a configurable outer shell of the Earth.
 * The uploaded real Universe save proved that all 137376 source blocks are
 * correctly present on disk; the remaining failure happens in the old 0.14
 * client's chunk renderer.  A one-block shell keeps the exact visible skin while minimizing the
 * compressed FullChunkData payload of dense Earth columns.
 */
final class EarthAsset{
    private $data = "";
    private $width = 0;
    private $height = 0;
    private $length = 0;
    private $shellMasks = [];

    public static function load($path){
        if($path === "" || !is_file($path)){
            return null;
        }
        $raw = @file_get_contents($path);
        if(!is_string($raw)){
            return null;
        }
        if(substr($path, -3) === ".gz"){
            $decoded = @gzdecode($raw);
            if(!is_string($decoded)){
                return null;
            }
            $raw = $decoded;
        }
        if(strlen($raw) < 10 || substr($raw, 0, 4) !== "UAE1"){
            return null;
        }
        $size = unpack("nwidth/nheight/nlength", substr($raw, 4, 6));
        $width = (int) $size["width"];
        $height = (int) $size["height"];
        $length = (int) $size["length"];
        if($width <= 0 || $height <= 0 || $length <= 0){
            return null;
        }
        $expected = $width * $height * $length * 2;
        if(strlen($raw) - 10 !== $expected){
            return null;
        }
        $asset = new self();
        $asset->width = $width;
        $asset->height = $height;
        $asset->length = $length;
        $asset->data = substr($raw, 10);
        return $asset;
    }

    public function getSize(){
        return [$this->width, $this->height, $this->length];
    }

    private function index($x, $y, $z){
        return (($y * $this->length + $z) * $this->width + $x) * 2;
    }

    public function getBlockId($x, $y, $z){
        if($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height || $z < 0 || $z >= $this->length){
            return 0;
        }
        return ord($this->data[$this->index($x, $y, $z)]);
    }

    public function getBlockData($x, $y, $z){
        if($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height || $z < 0 || $z >= $this->length){
            return 0;
        }
        return ord($this->data[$this->index($x, $y, $z) + 1]);
    }

    /**
     * Build a one-byte-per-voxel mask for the visible shell. A block is kept
     * when air/outside can be reached within $thickness blocks along at least
     * one of the six cardinal rays.  This always preserves the complete outer
     * surface and a few backing layers without having to assume a perfect
     * mathematical sphere.
     */
    public function getShellMask($thickness){
        $thickness = max(1, min(8, (int) $thickness));
        if(isset($this->shellMasks[$thickness])){
            return $this->shellMasks[$thickness];
        }
        $volume = $this->width * $this->height * $this->length;
        $mask = str_repeat("\x00", $volume);
        $dirs = [[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]];
        for($y = 0; $y < $this->height; ++$y){
            for($z = 0; $z < $this->length; ++$z){
                for($x = 0; $x < $this->width; ++$x){
                    if($this->getBlockId($x, $y, $z) === 0){
                        continue;
                    }
                    $keep = false;
                    foreach($dirs as $dir){
                        for($d = 1; $d <= $thickness; ++$d){
                            $nx = $x + $dir[0] * $d;
                            $ny = $y + $dir[1] * $d;
                            $nz = $z + $dir[2] * $d;
                            if($nx < 0 || $nx >= $this->width || $ny < 0 || $ny >= $this->height || $nz < 0 || $nz >= $this->length
                                || $this->getBlockId($nx, $ny, $nz) === 0){
                                $keep = true;
                                break 2;
                            }
                        }
                    }
                    if($keep){
                        $mask[($y * $this->length + $z) * $this->width + $x] = "\x01";
                    }
                }
            }
        }
        $this->shellMasks[$thickness] = $mask;
        return $mask;
    }

    public function isShellBlock($x, $y, $z, $thickness, $mask = null){
        if($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height || $z < 0 || $z >= $this->length){
            return false;
        }
        if($this->getBlockId($x, $y, $z) === 0){
            return false;
        }
        if(!is_string($mask)){
            $mask = $this->getShellMask($thickness);
        }
        return ord($mask[($y * $this->length + $z) * $this->width + $x]) !== 0;
    }
}
