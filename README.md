# UniverseAdventure 1.0.12

MCPE 0.14.x / API 2.0.0。

本版针对 1.0.11 在 Universe -> Earth 高频中转时偶发的「客户端只剩红色 Nether 背景、手臂抖动，随后落地确认超时并被留在 Nether」问题。

## 1.0.12 关键修复

- 不再把 `FullChunkDataPacket 已发送/usedChunks=true` 当成「客户端已经完成 Nether 场景切换」。
- 真实进入 Nether 后，先重发 3x3 中转区块、发送 PLAYER_SPAWN / MODE_RESET，并静置 40 tick，再开始飞行态复位。
- 飞行态复位仍使用受保护的真实 Creative -> 原游戏模式脉冲，物品栏自动清空在脉冲期间被临时关闭，并有完整背包快照校验。
- 不再依赖 MCPE 0.14 客户端自然下落 1 格来证明退出飞行。模式恢复后直接把玩家脚放到 Y=112 的黑曜石台面，发送 grounded MODE_RESET，并连续确认位置、游戏模式、allowFlight 与脚下实体方块。
- 落地等待期间不再每 tick 重发 AdventureSettings，减少手臂/物品栏视觉抽搐。
- 如果 Nether 客户端场景仍未稳定，会先重发真实区块再重试一次；第二次仍失败时不再把玩家困在 Nether，而是通过真实 Nether -> Universe 维度切换安全返回起点。
- 新建 transition 会记录来源世界与位置；服务端中途重启时也会持久化这些来源信息。
- 保留 1.0.11 的 transition 防覆盖、原游戏模式恢复、物品栏保护、Earth/Planet 真实 Nether 二跳和羊毛星球修复。

## 升级

完整 `stop` 后覆盖插件并启动。不要删除 `plugins/UniverseAdventure/` 数据目录或任何世界。

如果玩家已经被旧版 1.0.11 留在 Nether：完整重启并重新进入。若旧 transition 已经被取消，插件现有的 Nether 登录恢复逻辑会把玩家送回安全世界；若 transition 仍在 `pending-transitions.yml`，1.0.12 会按新的稳定流程继续。
