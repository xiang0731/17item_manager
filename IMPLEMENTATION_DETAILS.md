# 17 物品管理系统：实现细节与可执行修改指南

> 目标：将实现说明拆分为数据库层、API 层、UI 层，方便按职责快速定位与修改。
>
> 行号基于当前版本：`index.php` 约 8589 行，`README.md` 约 228 行。后续行号漂移时请用本文提供的命令重新定位。

## 目录

1. [使用方式](#sec-usage)
2. [快速定位命令](#sec-commands)
3. [数据库与迁移层](#sec-db)
4. [API 与业务层](#sec-api)
5. [前端 UI 与交互层](#sec-ui)
6. [发布与文档同步](#sec-release)
7. [常见改动模板](#sec-templates)
8. [最小回归清单](#sec-regression)

---

## <a id="sec-usage"></a>使用方式

1. 先看你要改的层：数据库、API、UI。
2. 再按“功能条目”找到对应行号。
3. 按条目里的“修改步骤”改最小闭环。
4. 按“验证”做手测。

---

## <a id="sec-commands"></a>快速定位命令

```bash
cd /Users/stevechen/DataSync/MyProjects/17ItemManager

# 查看局部代码
nl -ba index.php | sed -n '2800,3005p'

# 提醒相关
rg -n "item_reminder_instances|complete-reminder|undo-reminder" index.php

# 购物清单相关
rg -n "shopping-list|openShoppingListAndEdit|convertShoppingItem" index.php

# 清单状态切换与分组
rg -n "pending_purchase|pending_receipt|shopping-list/update-status|toggleCurrentShoppingStatus" index.php

# 展示模式与演示数据
rg -n "system/load-demo|demoItems|demoShoppingList|reminder_next_date|sqlite_sequence" index.php

# 账号与权限（v1.5）
rg -n "getAuthDB|auth/init|auth/login|auth/demo-login|auth/users|auth/admin-reset-password|renderUserManagement" index.php

# 公共频道（v1.5）
rg -n "public-channel|public_shared_items|public_shared_comments|recommend_reason|comment-delete" index.php

# 日期占位相关
rg -n "DATE_PLACEHOLDER_TEXT|data-date-placeholder|refreshDateInputPlaceholderDisplay" index.php

# 语法检查
php -l index.php
```

---

## <a id="sec-db"></a>数据库与迁移层

### 1. 核心表结构

- `index.php:87-146`：认证库 `auth_db.sqlite`（`users` 表，含角色/验证问题/最近登录）
- `index.php:67-85`：按用户隔离物品库（`items_db.sqlite` 与 `items_db_u{ID}.sqlite`）
- `index.php:214-252`：`shopping_list`（含 `status`、`reminder_date`、`reminder_note`）
- `index.php:254-278`：`items`（含 `reminder_date`、`reminder_next_date`、`reminder_cycle_*`、`reminder_note`）
- `index.php:280-293`：`item_reminder_instances`（提醒实例表 + 索引）

### 2. 迁移逻辑（兼容旧库）

- `index.php:115-193`
- 关键点：
1. 为旧库补齐提醒字段与购物清单字段。
2. 回填 `items.reminder_next_date = reminder_date`（`198` 行）。
3. 统一旧状态值：`待购买` -> `pending_purchase`，`待收货` -> `pending_receipt`（`202-204` 行）。

### 3. 提醒核心函数（后端）

- `index.php:419-437`：`calcNextReminderDate()`
- `index.php:439-450`：`isReminderConfigValid()`
- `index.php:452-492`：`syncItemReminderInstances()`
- `index.php:494-506`：`seedReminderInstancesFromItems()`

### 4. 改数据字段时的最小闭环

1. 建表 SQL（新库）。
2. 迁移 SQL（旧库）。
3. API 的新增/更新入参。
4. 表单回填与提交。
5. 导入导出兼容（如该字段会进备份）。

---

## <a id="sec-api"></a>API 与业务层

### 1. API 总入口与主路由

- `index.php:511`：API 入口
- `index.php:522-556`：`dashboard`
- `index.php:559-735`：`items` / `items/update`
- `index.php:738-882`：`items/complete-reminder` / `items/undo-reminder`
- `index.php:1609-1740`：`shopping-list`（CRUD + update-status + convert）
- `index.php:1327-1385`：回收站
- `index.php:1388-1456`：分类/位置
- `index.php:1680-1829`：导出/导入

### 2. 功能条目（API 侧）

### 2.0 账号体系与权限（v1.5）

- 代码位置
- `index.php:923-952`：`auth/init`（返回默认管理员、默认 demo、认证状态）
- `index.php:954-1022`：`auth/register`（含验证问题与答案）
- `index.php:1024-1060`：`auth/login`
- `index.php:1062-1125`：`auth/demo-login`（一键 demo 登录 + 演示数据装载）
- `index.php:1127-1216`：`auth/logout` / 忘记密码相关接口
- `index.php:1225-1285`：`auth/users` / `auth/admin-reset-password`（管理员能力）

- 修改步骤
1. 新增认证字段时先改 `getAuthDB()` 中 `users` 建表与兼容迁移。
2. 新增登录态初始化字段时同步改 `auth/init` 与登录页 `initAuthView()`。
3. 涉及管理员能力时必须同时加后端 `isAdminUser()` 校验与前端入口隐藏。

- 验证
- 普通用户无法访问 `auth/users` 与 `auth/admin-reset-password`。
- Demo 按钮可一键登录测试用户并进入完整演示数据。

### 2.1 备忘提醒：待完成/已完成/撤销 + 下一条实例

- 代码位置
- `index.php:738-816`：完成
- `index.php:818-882`：撤销
- `index.php:805-806`：完成后更新 `reminder_next_date`
- `index.php:871-872`：撤销后回滚 `reminder_next_date`

- 修改步骤
1. 改完成规则：优先改 `items/complete-reminder` 事务段。
2. 改撤销规则：同步改 `items/undo-reminder`，保持可逆。
3. 若改周期算法：同步改 `calcNextReminderDate()`。

- 验证
- 待完成 -> 已完成，会生成下一条。
- 撤销后回到待完成，并删除对应未完成子记录。

### 2.2 购物清单 CRUD 与入库

- 代码位置
- `index.php:1609-1688`：列表/新增/更新/状态切换/删除
- `index.php:1701-1740`：`shopping-list/convert`

- 修改步骤
1. 新增字段时同步 `shopping-list` 与 `shopping-list/update`。
2. 状态切换走 `shopping-list/update-status`，不要直接复用 `shopping-list/update`。
3. 入库映射规则改动时修改 `shopping-list/convert` 的 insert 数据。

- 验证
- 新增/编辑/删除正常。
- 切换状态后，列表分组与徽标同步变化。
- 入库后清单项被删除，物品新增成功。

### 2.3 仪表盘“备忘提醒”数据合并

- 代码位置
- `index.php:534-553`：物品提醒实例查询
- `index.php:554`：购物清单提醒查询
- `index.php:555`：统一返回

- 修改步骤
1. 改时间窗口时同步改 SQL 条件与前端文案。
2. 改排序策略时同时改前端 merge 排序。

- 验证
- 物品提醒和清单提醒都能出现在备忘提醒区。

### 2.4 展示模式数据补充（演示提醒与购物清单）

- 代码位置
- `index.php:704-909`：`loadDemoDataIntoDb()`（统一演示数据装载函数）
- `index.php:756-885`：演示物品 + 购物清单数据（含状态、提醒、回收站样例）
- `index.php:831-859`：预置“已完成提醒 + 下一条提醒”样例
- `index.php:1062-1125`：`auth/demo-login` 复用演示数据函数
- `index.php:1816-1821`：`system/load-demo` 复用演示数据函数

- 修改步骤
1. 新增演示字段时，先补 `demoItems` / `demoShoppingList` 两组数据。
2. 若演示需要“已完成”态，优先在 `item_reminder_instances` 上做事务化插入/更新。
3. 调整演示清理逻辑时，保持 `shopping_list` 与 `item_reminder_instances` 一起清空并重置序列。

- 验证
- 加载展示模式后，仪表盘可直接看到循环提醒已完成样例与购物提醒。
- 购物清单展示“待购买/待收货”两类演示数据。

---

## <a id="sec-ui"></a>前端 UI 与交互层

### 1. 视图路由与全局状态

- `index.php:3442-3483`：`switchView()` / `renderView()`
- `index.php:3306-3325`：`App`（含 `pendingShoppingEditId`）
- `index.php:3495-3665`：仪表盘渲染
- `index.php:4379-4598`：购物清单交互主逻辑

### 2. 物品编辑弹窗相关

- `index.php:2803-2919`：物品弹窗 DOM
- `index.php:2922-2929`：未保存确认弹窗
- `index.php:3264-3304`：未保存检测与处理
- `index.php:4128-4195`：新增/编辑回填
- `index.php:4217-4264`：保存提交
- `index.php:4293-4301`：关闭拦截

### 2.1 条码/序列号在过期日期后

- 代码位置
- `index.php:2854-2860`
- `index.php:4166-4173`
- `index.php:4229-4231`

- 修改步骤
1. 只动 DOM 顺序，不改输入框 `id`。
2. 如更换字段名，要同步 `editItem()` / `saveItem()` / 后端 SQL。

- 验证
- 字段顺序正确，保存回填正常。

### 2.2 关闭弹窗前提醒保存（忽略修改 / 保存修改）

- 代码位置
- `index.php:2922-2929`
- `index.php:3266`（脏检查字段清单）
- `index.php:4293-4301`

- 修改步骤
1. 新增可编辑字段时，加入 `getItemFormState()` 的 `ids` 数组。
2. 改按钮逻辑时分别调整 `discardItemChangesAndClose()` / `saveItemChangesAndClose()`。

- 验证
- 编辑后关闭会弹窗；忽略与保存行为正确。

### 2.3 循环提醒同一行布局 + 输入高度一致

- 代码位置
- `index.php:2862-2883`
- `index.php:2871-2877`（每 + 数值 + 单位）
- `index.php:2866`、`2872`、`2873`、`2882`（`!h-10 !py-0`）

- 修改步骤
1. 只调整该三列 grid 与 flex，不拆字段到下一行。
2. 保持四个控件高度一致（日期、数字、单位、下次日期）。

- 验证
- “每 * 天/周/年”与“下次提醒日期”在同一水平线且同高度。

### 2.4 循环周期仅天/周/年，文案“每 X 天/周/年”

- 代码位置
- `index.php:3133-3136`（下拉选项）
- `index.php:4442`（前端兜底）
- `index.php:4511-4520`（提交归一化）
- `index.php:6503+`（`reminderCycleLabel()` 展示文案）

- 修改步骤
1. 新增单位必须同步前后端，不可只改 UI。
2. 仅改展示词时只改 `reminderCycleLabel()`。

- 验证
- 仪表盘、详情、卡片文案一致。

### 2.5 下次提醒日期可手动修改

- 代码位置
- `index.php:3142`
- `index.php:4443`
- `index.php:4513`

- 修改步骤
1. 保持 `#itemReminderNext` 可编辑。
2. 若做自动覆盖逻辑，避免覆盖用户手填值。

- 验证
- 手填下次提醒后可保存并在下次编辑回填。

### 2.6 日期空值占位 + 去除空/有值切换变大效果

- 代码位置
- `index.php:3669-3688`
- `index.php:2350-2354`
- `index.php:4410-4421`（新增时刷新占位）

- 修改步骤
1. 占位文案改 `DATE_PLACEHOLDER_TEXT`。
2. 高度抖动优先检查 `input[data-date-placeholder="1"]` 固定高度样式。

- 验证
- 空值显示 `____年/__月/__日`。
- 选择日期后输入框尺寸不变。

### 2.7 公共频道共享、推荐理由与评论（v1.5）

- 代码位置
- `index.php:2428-2619`：`public-channel` 列表接口（含评论列表与权限标记）
- `index.php:2622-2684`：`public-channel/update`（仅发布者可编辑共享属性）
- `index.php:2686-2735`：`public-channel/comment`（所有用户可评论）
- `index.php:2737-2762`：`public-channel/comment-delete`（仅评论者或管理员可删除）
- `index.php:6277-6388`：公共频道前端交互（编辑弹窗、评论发布、评论删除）

- 修改步骤
1. 调整共享字段时，先改 `getItemShareSnapshot()` 与 `upsertPublicSharedItem()`。
2. 涉及评论权限时，后端接口和前端按钮显示必须同时校验，避免仅前端限制。
3. 若共享记录清理策略变更，需同步处理评论级联清理，避免孤儿评论。

- 验证
- 发布者可编辑共享物品；非发布者不可编辑。
- 任意登录用户可评论；仅评论者本人或管理员可删除评论。
- 共享物品加入购物清单后，备注中带有推荐理由。

### 3. 购物清单页面与弹窗

- `index.php:3193-3261`：购物清单弹窗 DOM
- `index.php:4695-4796`：购物清单页面卡片与分组
- `index.php:4798-4916`：新增/编辑/保存/状态切换
- `index.php:4932-4975`：已购买入库流程

### 3.0 登录页与用户管理页（v1.5）

- `index.php:2387-2770`：登录/注册/忘记密码 UI（含 Demo 按钮）
- `index.php:2676-2684`：`loginAsDemo()`
- `index.php:3772-3774`：侧边栏用户管理入口（管理员可见）
- `index.php:7186-7248`：`renderUserManagement()`（用户列表 + 重置密码）

### 3.1 去除“预期分类”

- 代码位置
- `index.php:3203`（隐藏 `shoppingCategoryId`）

- 修改步骤
1. 当前实现为隐藏字段，不在 UI 暴露“预期分类”。
2. 若恢复该字段，需新增可见选择器与选项填充。

### 3.2 提醒日期与提醒备注同一行（左日期、右备注）

- 代码位置
- `index.php:3232-3240`

- 修改步骤
1. 调整比例时改 `grid-cols-[170px_minmax(0,1fr)]`。

- 验证
- 两字段同行显示且对齐。

### 3.3 购物清单卡片提醒备注单行截断（无值空行）

- 代码位置
- `index.php:4717`（空值兜底 `&nbsp;`）
- `index.php:4734`（单行截断样式）

- 修改步骤
1. 保留空值 `&nbsp;`，避免卡片高度抖动。
2. 单行截断保持 `truncate h-4 leading-4`。

- 验证
- 无备注也有占位空行，长文本显示省略号。

### 3.4 购物清单状态分组（待购买/待收货）

- 代码位置
- `index.php:4645-4657`：状态标准化与展示映射
- `index.php:4708-4709`：分组数组
- `index.php:4760-4780`：待购买/待收货两段渲染
- `index.php:4776-4779`：待收货为空时不显示占位文案

- 修改步骤
1. 分组顺序固定：先渲染 `pending_purchase`，再渲染 `pending_receipt`。
2. 若要新增状态，先改 `shoppingStatusKey()` 与 `shoppingStatusMeta()`，再扩展分组渲染。

- 验证
- 新增/编辑后，卡片会进入对应状态分组。
- 待收货为空时页面不显示“暂无待收货清单”。

### 3.5 编辑清单左下角“已购买入库”按钮

- 代码位置
- `index.php:3249-3250`
- `index.php:4828`
- `index.php:4875-4883`

- 修改步骤
1. 仅编辑态显示按钮。
2. 点按先关闭清单弹窗，再进入入库弹窗。

- 验证
- 编辑态有按钮，新增态无按钮。

### 3.6 入库弹窗复用物品编辑页，按钮“保存入库”

- 代码位置
- `index.php:4932-4975`
- `index.php:3175`（`itemSubmitLabel`）
- `index.php:4964`（`setItemSubmitLabel('保存入库')`）

- 修改步骤
1. 预填策略在 `4941-4962`。
2. 提交文案统一走 `setItemSubmitLabel('保存入库')`。

- 验证
- 按钮文案正确，保存后清单项自动删除。

### 3.7 清单弹窗长度与备注默认行数

- 代码位置
- `index.php:3195`（弹窗高度/宽度）
- `index.php:2050-2057`（全局弹窗基础）
- `index.php:3244`（备注 `rows`）

- 修改步骤
1. 只改清单弹窗长度：改 `2935` 的 `min-height`。
2. 备注默认行数：改 `rows`。

### 3.8 仪表盘“查看清单”直达并打开对应编辑窗口

- 代码位置
- `index.php:3881`（按钮）
- `index.php:4685-4693`（`openShoppingListAndEdit`）
- `index.php:4791-4795`（进入清单后自动打开编辑窗）
- `index.php:3581`（`pendingShoppingEditId` 状态）

- 修改步骤
1. 跳转并自动打开依赖 `pendingShoppingEditId`。
2. 若只要跳转不要自动编辑，移除 `4791-4795` 自动触发逻辑。

### 3.9 编辑清单状态快捷切换（自动保存并关闭）

- 代码位置
- `index.php:3251-3253`：弹窗按钮 `shoppingToggleStatusBtn`
- `index.php:4659-4676`：按钮目标状态与文案更新
- `index.php:4843-4872`：点击后调用 `shopping-list/update-status`

- 修改步骤
1. 按钮文案由目标状态驱动：当前待购买 -> 按钮显示“已购买”；当前待收货 -> 按钮显示“待购买”。
2. 切换后同步更新本地 `App.shoppingList`，并执行 `closeShoppingModal(); renderView();`。

- 验证
- 点击按钮后状态即时切换。
- 弹窗自动关闭，列表分组与计数同步刷新。

### 4. 仪表盘与布局样式优化

### 4.1 备忘提醒（原循环提醒）命名与布局

- 代码位置
- `index.php:3833`（标题）
- `index.php:3863`（备注一行展示）

### 4.2 位置管理：名称右侧描述、超长省略、行高对齐图标

- 代码位置
- `index.php:5128`

### 4.3 浅色模式中尺寸卡片按钮视觉优化

- 代码位置
- `index.php:2446-2481`（深色基线）
- `index.php:2763-2794`（浅色覆盖）
- `index.php:4264`（渲染挂载）

### 4.4 状态管理图标下拉在浅色模式的样式修复

- 代码位置
- `index.php:2374-2392`：图标下拉菜单基础样式
- `index.php:2713-2730`：浅色模式下拉菜单覆盖样式
- `index.php:3398-3443`：图标下拉交互逻辑

### 4.5 仪表盘提醒卡片与按钮配色优化（深浅色一致性）

- 代码位置
- `index.php:2817-2895`：过期提醒/备忘提醒卡片基线与浅色模式色阶
- `index.php:2897-2930`：浅色模式按钮文字、边框、背景对比（查看清单/待完成/已完成/撤销）

### 4.6 备忘提醒统计细分 + 分类/状态单位统一“件”

- 代码位置
- `index.php:3778-3780`：备忘提醒分项计数（过期/循环/购物）
- `index.php:3834`：右上角统计文案
- `index.php:3928`：分类统计单位“件”
- `index.php:3948`：状态统计单位“件”

---

## <a id="sec-release"></a>发布与文档同步

- 页面内置更新记录：`index.php` 中 `const CHANGELOG = [...]`
- 当前版本号来源：`index.php` 中 `const APP_VERSION = CHANGELOG[0].version`
- README 版本记录：`README.md` 的“更新记录”章节（当前置顶 `v1.5.0`）
- README 功能总览：`README.md` 的“功能概览”章节（含公共频道）

发布新功能建议同步顺序：
1. 先改 `index.php` 的 `CHANGELOG`。
2. 再改 `README.md` 功能概览与版本记录。
3. 最后更新本文对应功能条目与行号。

---

## <a id="sec-templates"></a>常见改动模板

### 模板 A：新增一个物品日期字段

1. 建表与迁移：`index.php:79-99`, `115-193`。
2. API：`items` / `items/update`（`649-733`）。
3. UI：物品弹窗 DOM（`2803-2919`）。
4. 回填与提交：`editItem()`（`4153+`）、`saveItem()`（`4217+`）。
5. 日期占位：确保是 `type="date"`（自动绑定 `3407-3419`）。

### 模板 B：修改“提醒完成后”的规则

1. 改完成事务：`index.php:738-816`。
2. 改撤销事务：`index.php:818-882`。
3. 改前端按钮与 toast：`index.php:3576-3613`, `4272-4290`。

### 模板 C：改购物清单编辑弹窗布局

1. DOM：`index.php:3193-3261`。
2. 回填：`index.php:4816-4840`。
3. 提交：`index.php:4885-4916`。
4. 卡片展示：`index.php:4719-4740`。

---

## <a id="sec-regression"></a>最小回归清单

1. 物品：新增、编辑、关闭前未保存提醒。
2. 循环提醒：设置 -> 待完成 -> 已完成 -> 撤销。
3. 购物清单：新增 -> 编辑 -> 状态切换（自动保存并关闭）-> 已购买入库 -> 清单项移除。
4. 仪表盘备忘提醒：物品提醒与清单提醒都可显示。
5. “查看清单”跳转：能直达购物清单并打开对应编辑窗。
6. 日期输入：空值占位与有值状态尺寸一致。
7. 购物状态分组：待购买/待收货分组与计数、空状态文案符合预期。
8. 展示模式：加载后含购物清单与已完成提醒样例，重置后不会残留旧实例。
9. 深浅色切换：中尺寸卡片按钮、状态图标下拉、提醒操作按钮视觉正常。
10. 执行 `php -l index.php` 无语法错误。
