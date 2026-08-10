<?php

namespace UniverseAdventure\task;

use UniverseAdventure\Main;
use pocketmine\scheduler\PluginTask;

class UniverseTickTask extends PluginTask{
    public function __construct(Main $plugin){
        parent::__construct($plugin);
    }

    public function onRun($currentTick){
        $this->owner->tickUniverse($currentTick);
    }
}

