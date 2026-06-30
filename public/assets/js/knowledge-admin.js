const titleEl = document.getElementById('title');
const sourceEl = document.getElementById('source');
const contentEl = document.getElementById('content');
const saveBtn = document.getElementById('saveBtn');
const resultEl = document.getElementById('result');
const refreshBtn = document.getElementById('refreshBtn');
const syncBtn = document.getElementById('syncBtn');
const chunkListEl = document.getElementById('chunkList');
const cancelEditBtn = document.getElementById('cancelEditBtn');
const fileTitleEl = document.getElementById('fileTitle');
const knowledgeFileEl = document.getElementById('knowledgeFile');
const uploadBtn = document.getElementById('uploadBtn');

let editingId = null;

/**
 * 在结果区域展示接口返回，便于后台操作后直接排查错误信息。
 */
function showResult(value) {
    resultEl.style.display = 'block';
    resultEl.textContent = typeof value === 'string'
        ? value
        : JSON.stringify(value, null, 2);
}

/**
 * 渲染知识片段列表，所有内容都通过 textContent 写入，避免后台文本注入 HTML。
 */
function renderKnowledgeChunks(items) {
    chunkListEl.innerHTML = '';

    if (items.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'chunk-empty';
        empty.textContent = '暂无知识片段';
        chunkListEl.appendChild(empty);
        return;
    }

    items.forEach(item => {
        const row = document.createElement('div');
        row.className = 'chunk-item';

        const header = document.createElement('div');
        header.className = 'chunk-header';

        const title = document.createElement('strong');
        title.textContent = `#${item.id} ${item.title || '未命名片段'}`;

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'edit-btn';
        editBtn.textContent = '编辑';
        editBtn.addEventListener('click', function () {
            editKnowledgeChunk(item.id);
        });

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'delete-btn';
        deleteBtn.textContent = '删除';
        deleteBtn.addEventListener('click', function () {
            deleteKnowledgeChunk(item.id);
        });

        const operator = document.createElement('div');
        operator.className = 'chunk-operator';
        operator.appendChild(editBtn);
        operator.appendChild(deleteBtn);
        header.appendChild(operator);

        const meta = document.createElement('div');
        meta.className = 'chunk-meta';
        meta.textContent = `来源：${item.source || '知识库'} · 长度：${item.content_length || 0} · ${item.created_at || ''}`;

        const preview = document.createElement('div');
        preview.className = 'chunk-preview';
        preview.textContent = item.preview || '';

        header.appendChild(title);
        header.appendChild(operator);
        row.appendChild(header);
        row.appendChild(meta);
        row.appendChild(preview);
        chunkListEl.appendChild(row);
    });
}

/**
 * 从后端加载最近知识片段，用于录入后确认和日常删除管理。
 */
async function loadKnowledgeChunks() {
    chunkListEl.textContent = '加载中...';

    try {
        const data = await ApiClient.listKnowledgeChunks();

        if (!data.ok) {
            chunkListEl.textContent = '加载失败：' + (data.error || '');
            return;
        }

        renderKnowledgeChunks(data.items || []);
    } catch (error) {
        chunkListEl.textContent = '请求失败：' + error.message;
    }
}

async function editKnowledgeChunk(id) {
    const data = await ApiClient.getKnowledgeChunk(id);

    if (!data.ok) {
        alert(data.error || '读取失败');
        return;
    }

    editingId = id;

    titleEl.value = data.item.title || '';
    sourceEl.value = data.item.source || '';
    contentEl.value = data.item.content || '';

    saveBtn.textContent = '更新并同步';
    cancelEditBtn.style.display = 'inline-block';

    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

cancelEditKnowledgeChunk = function () {
    titleEl.value = '';
    contentEl.value = '';
    editingId = null;
    saveBtn.textContent = '保存';
    cancelEditBtn.style.display = 'none';
};

/**
 * 删除单条知识片段，后端会同时清理 MySQL 和 Qdrant。
 */
async function deleteKnowledgeChunk(id) {
    if (!confirm('确认删除这个知识片段吗？')) {
        return;
    }

    try {
        const result = await ApiClient.deleteKnowledgeChunk(id);
        showResult(result);

        if (result.ok) {
            loadKnowledgeChunks();
        }
    } catch (error) {
        showResult('请求失败：' + error.message);
    }
}

/**
 * 全量重新同步向量库，适合 Qdrant 重建或历史数据需要重新生成向量时使用。
 */
async function syncKnowledgeChunks() {
    syncBtn.disabled = true;
    syncBtn.textContent = '同步中...';
    showResult('正在重新生成向量并同步 Qdrant...');

    try {
        const result = await ApiClient.syncKnowledgeChunks();
        showResult(result);

        if (result.ok) {
            loadKnowledgeChunks();
        }
    } catch (error) {
        showResult('请求失败：' + error.message);
    } finally {
        syncBtn.disabled = false;
        syncBtn.textContent = '重新同步 Qdrant';
    }
}

/**
 * 保存新知识内容，后端负责切分文本、保存 MySQL 并写入向量库。
 */
async function saveKnowledgeChunk() {
    const title = titleEl.value.trim();
    const source = sourceEl.value.trim();
    const content = contentEl.value.trim();

    if (!title || !content) {
        alert('标题和内容不能为空');
        return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = '保存中...';
    showResult('正在保存 MySQL，并同步 Qdrant...');

    try {
        const data = editingId
            ? await ApiClient.updateKnowledgeChunk(editingId, { title, source, content })
            : await ApiClient.createKnowledgeChunk({ title, source, content });

        showResult(data);

        if (data.ok) {
            titleEl.value = '';
            contentEl.value = '';
            editingId = null;
            saveBtn.textContent = '保存';
            cancelEditBtn.style.display = 'none';

            if (typeof loadKnowledgeChunks === 'function') {
                loadKnowledgeChunks();
            }
        }
    } catch (error) {
        showResult('请求失败：' + error.message);
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = '保存';
    }
}

uploadBtn.addEventListener('click', async function () {
  const file = knowledgeFileEl.files[0];

  if (!file) {
    alert('请选择文件');
    return;
  }

  const formData = new FormData();
  formData.append('file', file);
  formData.append('title', fileTitleEl.value.trim());

  uploadBtn.disabled = true;
  uploadBtn.textContent = '上传中...';

  resultEl.style.display = 'block';
  resultEl.textContent = '正在上传文件、切分内容、同步向量库...';

  try {
    const data = await ApiClient.uploadKnowledgeFile(formData);
    resultEl.textContent = JSON.stringify(data, null, 2);

    if (data.ok) {
      fileTitleEl.value = '';
      knowledgeFileEl.value = '';

      if (typeof loadKnowledgeChunks === 'function') {
        loadKnowledgeChunks();
      }
    }
  } catch (error) {
    resultEl.textContent = '上传失败：' + error.message;
  } finally {
    uploadBtn.disabled = false;
    uploadBtn.textContent = '上传并同步';
  }
});


saveBtn.addEventListener('click', saveKnowledgeChunk);
refreshBtn.addEventListener('click', loadKnowledgeChunks);
syncBtn.addEventListener('click', syncKnowledgeChunks);
cancelEditBtn.addEventListener('click', cancelEditKnowledgeChunk);

loadKnowledgeChunks();
