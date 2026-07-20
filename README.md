# PHP LLM Agent

一个用于教学和技术分享的 PHP 8.2 Agent 项目：不依赖 Agent 框架，从最小 LLM 调用开始，逐步实现 Function Calling、ReAct、Plan-and-Execute、MCP、Skills、Memory、上下文压缩和交互式 Coding Agent。

项目支持 OpenAI-compatible API，可在 DeepSeek 等云端模型和本地 Ollama 模型之间切换。

## 项目特点

- 通过 `bin/00_chat.php` 到 `bin/06_compare_models.php` 展示 Agent 能力的递进过程；
- 同时保留单次任务、交互式会话和项目级 Coding Agent 三种运行方式；
- 实现文件工具、精确编辑、搜索以及需要用户批准的 Shell 命令；
- 实现 stdio MCP Tools、Skills 渐进式加载和文件型长期 Memory；
- 支持旧工具输出清理、LLM 摘要压缩和多轮上下文管理；
- 使用 JSONL 记录完整请求、响应、工具调用、Token 和耗时；
- 支持云端模型与本地 Ollama 执行同一任务的对照实验。

## 环境要求

- PHP 8.2 或更高版本；
- PHP 扩展：`curl`、`json`、`mbstring`；
- Composer（可选，项目没有安装依赖时也能使用内置自动加载）；
- Ollama（仅使用本地模型时需要）。

检查 PHP 环境：

```bash
php -v
php -m | grep -E 'curl|json|mbstring'
```

## 快速开始

复制配置模板：

```bash
cp .env.example .env
```

配置云端 OpenAI-compatible API：

```dotenv
LLM_BASE_URL=https://api.deepseek.com/v1
LLM_API_KEY=replace-me
LLM_MODEL_ID=deepseek-chat
LLM_PROFILE=cloud
```

如需使用 Composer 的 PSR-4 自动加载，可执行：

```bash
composer dump-autoload
```

### 1. 单次 LLM 调用

```bash
php bin/00_chat.php --profile=cloud "用一句话解释为什么 LLM API 是无状态的"
```

使用本地 Ollama：

```bash
ollama serve
ollama pull qwen3.6:35b
php bin/00_chat.php --profile=local "用一句话解释为什么 LLM API 是无状态的"
```

### 2. 交互式 Agent

```bash
./bin/agent --profile=cloud
```

也可以直接运行：

```bash
php bin/chat.php --profile=cloud
```

交互式命令包括：

- `/help`：显示帮助；
- `/clear`：清空当前会话上下文；
- `/history`：查看最近消息；
- `/metrics`：查看 Token 和耗时；
- `/compact`：立即尝试压缩旧上下文；
- `/model`：查看当前模型；
- `/workspace`：查看当前工作区；
- `/exit`：退出。

### 3. 项目级 Coding Agent

进入任意目标项目后，通过本仓库的绝对路径启动 Agent：

```bash
cd /path/to/target-project
/absolute/path/to/my-agent/bin/agent --profile=cloud
```

启动目录默认就是 Agent 的文件工作区，也可以显式指定：

```bash
/absolute/path/to/my-agent/bin/agent \
  --profile=cloud \
  --workspace=/path/to/target-project
```

`run_command` 默认逐次请求批准。`--yes` 会自动批准命令，`--no-shell` 会完全禁用命令工具。

## 递进演示

- `bin/00_chat.php`：最小 Chat Completions 调用；
- `bin/01_prompt_tools.php`：通过 Prompt 和文本标签模拟工具协议；
- `bin/02_native_function_call.php`：原生 Function Calling；
- `bin/03_react_agent.php`：完整的模型、工具、Observation 循环；
- `bin/04_plan_execute.php`：Planner、多个 Executor 与最终汇总；
- `bin/05_modern_agent.php`：MCP、Skills、Memory 和 Context Manager；
- `bin/06_compare_models.php`：云端与本地模型执行同一文件任务。

运行现代 Agent 的默认贪吃蛇任务：

```bash
php bin/05_modern_agent.php --profile=cloud
```

运行模型对照实验：

```bash
php bin/06_compare_models.php --profile=all
```

## LLM Profile

- `default`：直接读取 `LLM_*` 配置；
- `cloud`：优先读取 `CLOUD_LLM_*`，未配置时回退到 `LLM_*`；
- `local`：读取 `LOCAL_LLM_*`，默认连接 `http://127.0.0.1:11434/v1`。

`bin/00_chat.php` 到 `bin/06_compare_models.php` 均支持 `--profile` 参数。交互式 Agent 的默认值由 `LLM_PROFILE` 控制。

## 日志与生成物

- 全链路日志：`var/logs/*.jsonl`；
- 演示工作区：`var/workspaces/`；
- 长期 Memory：`var/memory/`；
- 测试临时数据：`var/tests/`。

查看实时日志：

```bash
tail -f var/logs/*.jsonl
```

日志可能包含完整 Prompt、模型响应和工具参数，请勿写入密钥或其他敏感信息。

## 测试

```bash
php tests/run.php
```

测试覆盖工具注册与执行、目录边界、Memory、Skills、MCP Schema、上下文压缩、UTF-8 终端输入、日志时区和命令行参数解析。

## 目录概览

```text
bin/          递进演示和交互式入口
src/          Agent、LLM、工具、Context、Memory、Skills、MCP 等核心实现
mcp/          示例 MCP Server
skills/       Agent Skill 示例
examples/     可直接打开的演示生成物
tests/        无测试框架依赖的测试脚本
docs/         技术分享材料和项目源码导读
var/          运行日志、Memory、工作区和临时数据
```

## 进一步阅读

- [LLM 与 Agent 技术分享](docs/llm-agent-sharing.md)
- [项目源码导读](docs/project-source-guide.md)

## 当前边界

这是教学实现，不是生产级 Agent 平台。目前没有实现流式输出、向量数据库、完整 RAG、并行 Sub-agent、容器沙箱、多用户隔离或完整 MCP 协议能力。

## License

Proprietary，仅供内部教学、演示和研究使用。
