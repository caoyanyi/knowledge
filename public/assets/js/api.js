// 前端所有后端请求都通过这个对象访问版本化 API，避免页面直接依赖 PHP 脚本路径。
const ApiClient = {
    /**
     * 发送 JSON POST 请求，并返回后端的 JSON 响应。
     */
    async postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        return response.json();
    },

    /**
     * 发送 GET 请求，并返回后端的 JSON 响应。
     */
    async getJson(url) {
        const response = await fetch(url);

        return response.json();
    },

    /**
     * 发起流式聊天请求，调用方负责读取 ReadableStream。
     */
    sendChatStream(message, sessionId) {
        return fetch('/api/v1/chat/stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                message,
                session_id: sessionId
            })
        });
    },

    sendChat(message) {
        return this.postJson('/api/v1/chat', { message });
    },

    listSessions() {
        return this.getJson('/api/v1/sessions');
    },

    getSessionMessages(sessionId) {
        return this.getJson('/api/v1/sessions/' + encodeURIComponent(sessionId) + '/messages');
    },

    createKnowledgeChunk(payload) {
        return this.postJson('/api/v1/knowledge-chunks', payload);
    },

    listKnowledgeChunks() {
        return this.getJson('/api/v1/knowledge-chunks');
    },

    deleteKnowledgeChunk(id) {
        return fetch('/api/v1/knowledge-chunks/' + encodeURIComponent(id), {
            method: 'DELETE'
        }).then(response => response.json());
    },

    syncKnowledgeChunks() {
        return this.postJson('/api/v1/knowledge-chunks/sync', {});
    }
};
