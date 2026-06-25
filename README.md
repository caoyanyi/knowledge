# 企业 AI 知识库客服

一个基于 PHP 原生实现的企业知识库客服 Demo。前端提供聊天界面，后端调用 OpenAI-compatible Responses API，并通过 Qdrant 向量检索企业知识片段，支持会话历史记录。

## 功能

- 流式输出 AI 回复
- 基于 Qdrant 的知识库向量检索
- MySQL 保存会话和消息历史
- 支持 OpenAI-compatible 聊天模型和 Embedding 模型
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chat_logs_session_id (session_id),
  INDEX idx_chat_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

向 `knowledge_chunks` 写入企业资料后，可同步到 Qdrant。

也可以打开后台录入页直接写入 MySQL 并同步到 Qdrant：

```text
http://127.0.0.1:8000/knowledge_admin.html
```

## 初始化 Qdrant

启动 Qdrant 后，创建集合：

```bash
php qdrant_init.php
```

默认 `QDRANT_VECTOR_SIZE=4096`。如果你的 Embedding 模型输出维度不是 4096，请先在 `.env` 中修改该值，再初始化集合。

同步 MySQL 中的知识片段到 Qdrant：

```bash
php sync_knowledge_to_qdrant.php
```

## 本地启动

使用 PHP 内置服务器：

```bash
php -S 127.0.0.1:8000
```

然后打开：

```text
http://127.0.0.1:8000
```

## 文件说明

- `index.html`：聊天界面
- `knowledge_admin.html`：知识库录入和同步页面
- `script.js`：前端交互、流式读取、会话切换
- `style.css`：页面样式
- `app_helper.php`：环境读取、JSON 响应、PDO、OpenAI 请求等公共方法
- `knowledge_helper.php`：知识切片、Embedding、Qdrant、知识上下文构造等公共方法
- `chat_stream.php`：流式问答主接口，包含知识库检索和会话记录
- `chat.php`：非流式问答接口
- `save_knowledge.php`：保存知识内容并同步到 Qdrant
- `sessions.php`：会话列表接口
- `session_messages.php`：会话消息接口
- `qdrant_init.php`：Qdrant 集合初始化脚本
- `sync_knowledge_to_qdrant.php`：知识库向量同步脚本

## 安全说明

`.env`、日志、依赖目录、本地缓存和数据库文件已通过 `.gitignore` 排除。不要将真实 API Key、数据库密码或生产数据提交到 Git。
