<?php

namespace UniverseAdventure\entity;

use pocketmine\entity\Bat;
use pocketmine\entity\Entity;
use pocketmine\level\format\FullChunk;
use pocketmine\nbt\tag\CompoundTag;

/**
 * An invisible, zero-gravity Bat used only as a native MCPE riding surface.
 *
 * Do not use Boat here. Genisys 0.14.3 rewrites a linked Boat's position
 * from every rider MovePlayerPacket, which fights server-driven motion. A Bat
 * still gives MCPE a native riding link/interpolated entity, while leaving
 * position authority entirely with this plugin.
 */
class SpaceCarrier extends Bat{
    // Register by short save-name only; do NOT replace Genisys' normal Bat
    // registration. Bat::spawnTo() still sends the vanilla network type 19.
    const NETWORK_ID = -1;

    public $height = 0.2;
    public $width = 0.2;
    public $gravity = 0.0;
    public $drag = 0.0;

    public function __construct(FullChunk $chunk, CompoundTag $nbt){
        parent::__construct($chunk, $nbt);
        $this->gravity = 0.0;
        $this->drag = 0.0;
        $this->canCollide = false;
        $this->keepMovement = true;
        $this->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_INVISIBLE, true);
    }

    public function onUpdate($currentTick){
        if($this->closed){
            return false;
        }
        $tickDiff = $currentTick - $this->lastUpdate;
        if($tickDiff <= 0 && !$this->justCreated){
            return true;
        }
        $this->lastUpdate = $currentTick;
        $this->timings->startTiming();
        $hasUpdate = $this->entityBaseTick($tickDiff);

        // No vanilla mob AI/gravity. The plugin controls motion from
        // PlayerInputPacket and this entity supplies the client's smooth native
        // ride interpolation.
        if(abs($this->motionX) > 0.00001 || abs($this->motionY) > 0.00001 || abs($this->motionZ) > 0.00001){
            $this->move($this->motionX, $this->motionY, $this->motionZ);
        }
        $this->updateMovement();
        $this->timings->stopTiming();
        return $hasUpdate || abs($this->motionX) > 0.00001 || abs($this->motionY) > 0.00001 || abs($this->motionZ) > 0.00001;
    }

    public function getDrops(){
        return [];
    }
}
