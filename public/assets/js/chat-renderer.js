const SOURCE_MARKER = '__SOURCES_JSON__:';

function renderMarkdown(text) {
    const html = marked.parse(text);

    return DOMPurify.sanitize(html);
}

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
