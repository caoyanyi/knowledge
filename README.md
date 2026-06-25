# 企业 AI 知识库客服

一个基于 PHP 原生实现的企业知识库客服 Demo。前端提供聊天界面，后端调用 OpenAI-compatible Responses API，并通过 Qdrant 向量检索企业知识片段，支持会话历史记录。

## 功能

- 流式输出 AI 回复
- 基于 Qdrant 的知识库向量检索
- MySQL 保存会话和消息历史
- 支持 OpenAI-compatible 聊天模型和 Embedding 模型
- 提供知识库同步脚本和 Qdrant 集合初始化脚本

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

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_business_demo
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password

QDRANT_URL=http://127.0.0.1:6333
QDRANT_COLLECTION=knowledge_chunks
QDRANT_SCORE_THRESHOLD=0.45

EMBEDDING_BASE_URL=https://api.openai.com
EMBEDDING_API_KEY=your-embedding-api-key
EMBEDDING_MODEL=your-embedding-model
```

如果 `OPENAI_BASE_URL` 或 `EMBEDDING_BASE_URL` 已经包含 `/v1`，代码会自动复用该路径；否则会自动拼接 `/v1`。

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

## 初始化 Qdrant

启动 Qdrant 后，创建集合：

```bash
php qdrant_init.php
```

当前 `qdrant_init.php` 使用 `size = 4096`。如果你的 Embedding 模型输出维度不是 4096，请先修改该脚本里的向量维度，再初始化集合。

同步 MySQL 中的知识片段到 Qdrant：

```bash
php sync_knowledge_to_qdrant.php
```

测试检索结果：

```bash
php qdrant_search_test.php
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
- `script.js`：前端交互、流式读取、会话切换
- `style.css`：页面样式
- `chat_stream.php`：流式问答主接口，包含知识库检索和会话记录
- `chat.php`：非流式问答接口
- `sessions.php`：会话列表接口
- `session_messages.php`：会话消息接口
- `embedding_helper.php`：Embedding API 调用
- `qdrant_helper.php`：Qdrant 检索封装
- `qdrant_init.php`：Qdrant 集合初始化脚本
- `sync_knowledge_to_qdrant.php`：知识库向量同步脚本
- `qdrant_search_test.php`：向量检索测试脚本

## 安全说明

`.env`、日志、依赖目录、本地缓存和数据库文件已通过 `.gitignore` 排除。不要将真实 API Key、数据库密码或生产数据提交到 Git。
