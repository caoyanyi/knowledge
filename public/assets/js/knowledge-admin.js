const titleEl = document.getElementById('title');
const sourceEl = document.getElementById('source');
const contentEl = document.getElementById('content');
const saveBtn = document.getElementById('saveBtn');
const resultEl = document.getElementById('result');

saveBtn.addEventListener('click', async function () {
    const title = titleEl.value.trim();
    const source = sourceEl.value.trim();
    const content = contentEl.value.trim();

    if (!title || !content) {
        alert('标题和内容不能为空');
        return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = '保存中...';
    resultEl.style.display = 'block';
    resultEl.textContent = '正在保存 MySQL，并同步 Qdrant...';

    try {
        const data = await ApiClient.createKnowledgeChunk({
            title,
            source,
            content
        });

        resultEl.textContent = JSON.stringify(data, null, 2);

        if (data.ok) {
            titleEl.value = '';
            contentEl.value = '';
        }
    } catch (error) {
        resultEl.textContent = '请求失败：' + error.message;
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = '保存并同步到向量库';
    }
});
