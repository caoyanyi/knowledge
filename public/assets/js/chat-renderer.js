const SOURCE_MARKER = '__SOURCES_JSON__:';

/**
 * 渲染助手 Markdown，并通过 DOMPurify 清理模型输出中的不可信 HTML。
 */
function renderMarkdown(text) {
    const html = marked.parse(text);

    return DOMPurify.sanitize(html);
}

/**
 * 在聊天窗口追加一条消息，并返回气泡节点用于流式更新。
 */
function appendMessage(messagesEl, role, text) {
    const wrapper = document.createElement('div');
    wrapper.className = `message ${role}`;

    const bubble = document.createElement('div');
    bubble.className = 'bubble';

    if (role === 'assistant') {
        bubble.innerHTML = renderMarkdown(text);
    } else {
        bubble.textContent = text;
    }

    wrapper.appendChild(bubble);
    messagesEl.appendChild(wrapper);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    return bubble;
}

/**
 * 渲染后端随流式答案返回的知识库来源列表。
 */
function renderSources(sources) {
    if (sources.length === 0) {
        return null;
    }

    const sourceBox = document.createElement('div');
    sourceBox.className = 'source-box';

    sourceBox.innerHTML = DOMPurify.sanitize(`
  <div class="source-title">参考资料</div>
  ${sources.map(item => `
    <div class="source-item">
      <span>${item.title}</span>
      <small>来源：${item.source || '知识库'} · 相关度：${item.score}</small>
    </div>
  `).join('')}
`);

    return sourceBox;
}

/**
 * 解析流式响应：正文实时渲染，末尾的来源 JSON 单独显示为参考资料。
 */
function renderAssistantStream(bubble, rawStream) {
    let answer = rawStream;
    let sources = [];
    const markerIndex = rawStream.indexOf(SOURCE_MARKER);

    if (markerIndex >= 0) {
        answer = rawStream.slice(0, markerIndex).trim();
        const jsonText = rawStream.slice(markerIndex + SOURCE_MARKER.length).trim();

        try {
            const parsed = JSON.parse(jsonText);
            sources = parsed.sources || [];
        } catch (error) {
            // 流还没完整到达时 JSON 可能暂时不完整，下一次 chunk 会重新解析。
            sources = [];
        }
    }

    bubble.innerHTML = renderMarkdown(answer);

    const sourceBox = renderSources(sources);
    if (sourceBox) {
        bubble.appendChild(sourceBox);
    }

    return answer;
}
