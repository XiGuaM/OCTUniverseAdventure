<?php

namespace UniverseAdventure\world;

final class UniverseLayout{
    public static function sphereForCell($seed, $gridX, $gridZ, array $settings){
        $cell = max(32, (int) ($settings["cellSize"] ?? 64));
        $minRadius = max(2, (int) ($settings["minRadius"] ?? 4));
        $maxRadius = max($minRadius, (int) ($settings["maxRadius"] ?? 12));
        $minY = (int) ($settings["minY"] ?? 20);
        $maxY = (int) ($settings["maxY"] ?? 108);
        $exclusion = max(0, (int) ($settings["earthExclusion"] ?? 96));
        $earthX = (float) ($settings["earthX"] ?? 0);
        $earthZ = (float) ($settings["earthZ"] ?? 0);
        $blocks = $settings["blocks"] ?? [];
        if(count($blocks) === 0){
            return null;
        }

        $h1 = self::hash($seed, $gridX, $gridZ, 0x51f2);
        $h2 = self::hash($seed, $gridX, $gridZ, 0x9e37);
        $h3 = self::hash($seed, $gridX, $gridZ, 0x27d4);
        $radius = $minRadius + ($h1 % ($maxRadius - $minRadius + 1));
        $margin = $maxRadius + 4;
        $span = max(1, $cell - ($margin * 2));
        $x = $gridX * $cell + $margin + ($h2 % $span);
        $z = $gridZ * $cell + $margin + ($h3 % $span);
        $usableMinY = max($minY, $radius + 2);
        $usableMaxY = min($maxY, 125 - $radius);
        if($usableMaxY < $usableMinY){
            return null;
        }
        $y = $usableMinY + (self::hash($seed, $gridX, $gridZ, 0x1656) % ($usableMaxY - $usableMinY + 1));
        $earthDx = $x - $earthX;
        $earthDz = $z - $earthZ;
        if(($earthDx * $earthDx) + ($earthDz * $earthDz) < ($exclusion * $exclusion)){
            return null;
        }
        $block = self::selectBlock($seed, $gridX, $gridZ, $blocks);

        return [
            "key" => $gridX . ":" . $gridZ,
            "gridX" => (int) $gridX,
            "gridZ" => (int) $gridZ,
            "x" => (int) $x,
            "y" => (int) $y,
            "z" => (int) $z,
            "radius" => (int) $radius,
            "block" => [
                "name" => preg_replace('/[^a-z0-9]/', '', strtolower((string) ($block["name"] ?? "stone"))),
                "id" => (int) ($block["id"] ?? 1),
                "meta" => (int) ($block["meta"] ?? 0)
            ],
            "seed" => self::hash($seed, $gridX, $gridZ, 0x6a09)
        ];
    }

    public static function spheresForChunk($seed, $chunkX, $chunkZ, array $settings){
        $cell = max(32, (int) ($settings["cellSize"] ?? 64));
        $maxRadius = max(2, (int) ($settings["maxRadius"] ?? 12));
        $minX = ($chunkX << 4) - $maxRadius;
        $maxX = ($chunkX << 4) + 15 + $maxRadius;
        $minZ = ($chunkZ << 4) - $maxRadius;
        $maxZ = ($chunkZ << 4) + 15 + $maxRadius;
        $result = [];
        for($gx = self::floorDiv($minX, $cell); $gx <= self::floorDiv($maxX, $cell); ++$gx){
            for($gz = self::floorDiv($minZ, $cell); $gz <= self::floorDiv($maxZ, $cell); ++$gz){
                $sphere = self::sphereForCell($seed, $gx, $gz, $settings);
                if($sphere !== null){
                    $result[] = $sphere;
                }
            }
        }
        return $result;
    }

    public static function findTouchingSphere($seed, $x, $y, $z, array $settings, $padding = 1.35){
        $cell = max(32, (int) ($settings["cellSize"] ?? 64));
        $gx = self::floorDiv((int) floor($x), $cell);
        $gz = self::floorDiv((int) floor($z), $cell);
        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                $sphere = self::sphereForCell($seed, $gx + $dx, $gz + $dz, $settings);
                if($sphere === null){
                    continue;
                }
                $sx = $x - $sphere["x"];
                $sy = $y - $sphere["y"];
                $sz = $z - $sphere["z"];
                $distance = sqrt(($sx * $sx) + ($sy * $sy) + ($sz * $sz));
                if($distance <= $sphere["radius"] + $padding && $distance >= max(0, $sphere["radius"] - 2.25)){
                    return $sphere;
                }
            }
        }
        return null;
    }

    private static function hash($seed, $x, $z, $salt){
        $value = crc32((string) $seed . ":" . (int) $x . ":" . (int) $z . ":" . (int) $salt);
        if($value < 0){
            $value += 4294967296;
        }
        return (int) ($value & 0x7fffffff);
    }

    /**
     * Every ordinary entry is one choice. All wool colours together are one
     * choice, then a second deterministic roll chooses the colour.
     */
    private static function selectBlock($seed, $gridX, $gridZ, array $blocks){
        $choices = [];
        $wools = [];
        foreach($blocks as $block){
            if((int) ($block["id"] ?? 0) === 35){
                $wools[] = $block;
            }else{
                $choices[] = $block;
            }
        }
        if(count($wools) > 0){
            $choices[] = ["woolGroup" => true];
        }
        $choice = $choices[self::hash($seed, $gridX, $gridZ, 0x7f4a) % count($choices)];
        if(!empty($choice["woolGroup"])){
            return $wools[self::hash($seed, $gridX, $gridZ, 0x35c0) % count($wools)];
        }
        return $choice;
    }

    private static function floorDiv($value, $divisor){
        return (int) floor($value / $divisor);
    }
}
