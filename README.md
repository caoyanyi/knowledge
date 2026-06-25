# 企业 AI 知识库客服

一个基于 PHP 原生实现的企业知识库客服 Demo。前端提供聊天界面，后端调用 OpenAI-compatible Responses API，并通过 Qdrant 向量检索企业知识片段，支持会话历史记录。

## 功能

- 流式输出 AI 回复
- 基于 Qdrant 的知识库向量检索
- MySQL 保存会话和消息历史
- 支持 OpenAI-compatible 聊天模型和 Embedding 模型
- 前端通过统一 `/api/v1/*` API 请求后端
- 提供知识库管理页、同步脚本和 Qdrant 集合初始化脚本

## 运行环境

- PHP 8.0+
- PHP 扩展：`curl`、`pdo_mysql`、`mbstring`
- MySQL 5.7+ 或 8.0+
- Qdrant
- OpenAI-compatible API Key

## 配置

复制环境变量模板并填写真实配置：

```bash
cp .env.example .env
```

主要配置项：

```ini
OPENAI_BASE_URL=https://api.openai.com
OPENAI_API_KEY=your-openai-api-key
OPENAI_MODEL=your-chat-model
OPENAI_MAX_OUTPUT_TOKENS=4096

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_business_demo
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password

QDRANT_URL=http://127.0.0.1:6333
QDRANT_COLLECTION=knowledge_chunks
QDRANT_SCORE_THRESHOLD=0.45
QDRANT_VECTOR_SIZE=4096

EMBEDDING_BASE_URL=https://api.openai.com
EMBEDDING_API_KEY=your-embedding-api-key
EMBEDDING_MODEL=your-embedding-model

KNOWLEDGE_SEARCH_LIMIT=3
KNOWLEDGE_CHUNK_MAX_LENGTH=800
KNOWLEDGE_CHUNK_OVERLAP=100
CHAT_HISTORY_LIMIT=50
```

如果 `OPENAI_BASE_URL` 或 `EMBEDDING_BASE_URL` 已经包含 `/v1`，代码会自动复用该路径；否则会自动拼接 `/v1`。

可按需要调整：

- `OPENAI_INSTRUCTIONS`：客服助手系统提示词
- `OPENAI_MAX_OUTPUT_TOKENS`：单次回复最大输出 token
- `KNOWLEDGE_SEARCH_LIMIT`：每次问答检索的知识片段数量
- `KNOWLEDGE_CHUNK_MAX_LENGTH` / `KNOWLEDGE_CHUNK_OVERLAP`：后台录入知识时的切片大小和重叠长度
- `QDRANT_VECTOR_SIZE` / `QDRANT_DISTANCE`：Qdrant 集合向量维度和距离算法
- `EMBEDDING_TIMEOUT_SECONDS` / `QDRANT_TIMEOUT_SECONDS`：问答链路的知识库检索超时；超时后会降级为无参考资料回答，不阻断聊天
- `QDRANT_UPSERT_TIMEOUT_SECONDS`：后台录入或批量同步知识库时的向量写入超时
- `CHAT_HISTORY_LIMIT` / `SESSION_LIST_LIMIT`：会话上下文和会话列表数量

## 数据库表

创建业务数据库后，执行以下 SQL：

```sql
CREATE TABLE knowledge_chunks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  source VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chat_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(64) NOT NULL,
  user_message TEXT NOT NULL,
  assistant_answer MEDIUMTEXT NOT NULL,
  model VARCHAR(128) NOT NULL,
  request_time_ms INT UNSIGNED DEFAULT 0,
  sources_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chat_logs_session_id (session_id),
  INDEX idx_chat_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

已有旧表可执行以下 SQL 手动补充参考资料快照列；应用保存或读取历史消息时也会自动检测并补列：

```sql
ALTER TABLE chat_logs ADD COLUMN sources_json JSON NULL AFTER request_time_ms;
```

向 `knowledge_chunks` 写入企业资料后，可同步到 Qdrant。

也可以打开后台录入页直接写入 MySQL 并同步到 Qdrant：

```text
http://127.0.0.1:8000/knowledge_admin.html
```

## 初始化 Qdrant

启动 Qdrant 后，创建集合：

```bash
php bin/qdrant_cli.php init
```

默认 `QDRANT_VECTOR_SIZE=4096`。如果你的 Embedding 模型输出维度不是 4096，请先在 `.env` 中修改该值，再初始化集合。

同步 MySQL 中的知识片段到 Qdrant：

```bash
php bin/qdrant_cli.php sync
```

## 本地启动

使用 PHP 内置服务器：

```bash
php -S 127.0.0.1:8000 router.php
```

然后打开：

```text
http://127.0.0.1:8000
```

## API 路由

前端统一通过 API 路由访问后端，不直接请求具体 PHP 脚本：

- `POST /api/v1/chat/stream`：流式问答
- `POST /api/v1/chat`：非流式问答
- `GET /api/v1/sessions`：会话列表
- `GET /api/v1/sessions/{session_id}/messages`：会话消息
- `POST /api/v1/knowledge-chunks`：保存知识并同步到 Qdrant

## 文件说明

- `router.php`：PHP 内置服务器路由脚本
- `public/`：前端页面和静态资源
- `public/index.html`：聊天界面
- `public/knowledge_admin.html`：知识库录入和同步页面
- `public/assets/css/`：基础、聊天页、后台页样式
- `public/assets/js/api.js`：前端 API 客户端
- `public/assets/js/chat-renderer.js`：聊天消息和参考资料渲染
- `public/assets/js/chat.js`：聊天页交互入口
- `public/assets/js/knowledge-admin.js`：知识库管理页交互入口
- `src/`：后端 API 和公共方法
- `src/api.php`：统一 API 路由入口
- `src/api_handlers.php`：API handler 实现
- `src/app_helper.php`：环境读取、JSON 响应、PDO、OpenAI 请求等公共方法
- `src/knowledge_helper.php`：知识切片、Embedding、Qdrant、知识上下文构造等公共方法
- `bin/qdrant_cli.php`：Qdrant 集合初始化和知识库同步脚本

## 安全说明

`.env`、日志、依赖目录、本地缓存和数据库文件已通过 `.gitignore` 排除。不要将真实 API Key、数据库密码或生产数据提交到 Git。
