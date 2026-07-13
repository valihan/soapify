document.addEventListener('DOMContentLoaded', () => {
    let requestEditor = CodeMirror.fromTextArea(document.getElementById('request-editor'), {
        mode: "xml",
        theme: "dracula",
        lineNumbers: true,
        matchBrackets: true,
        autoCloseTags: true
    });

    let responseEditor = CodeMirror.fromTextArea(document.getElementById('response-editor'), {
        mode: "xml",
        theme: "dracula",
        lineNumbers: true,
        readOnly: true
    });

    let wsdls = [];
    let currentWsdlId = null;
    let currentMethod = null;
    let currentWsdlUrl = null;
    let currentSavedRequestName = '';

    // Elements
    const wsdlListEl = document.getElementById('wsdl-list');
    const btnAddWsdl = document.getElementById('btn-add-wsdl');
    const wsdlNameInput = document.getElementById('wsdl-name');
    const wsdlUrlInput = document.getElementById('wsdl-url');
    const currentMethodTitle = document.getElementById('current-method-title');
    const btnSendRequest = document.getElementById('btn-send-request');
    const btnSaveRequest = document.getElementById('btn-save-request');
    
    // Modal
    const modal = document.getElementById('save-modal');
    const btnCancelSave = document.getElementById('btn-cancel-save');
    const btnConfirmSave = document.getElementById('btn-confirm-save');
    const saveRequestNameInput = document.getElementById('save-request-name');

    // Load initial WSDLs
    loadWsdls();

    btnAddWsdl.addEventListener('click', async () => {
        const name = wsdlNameInput.value.trim();
        const url = wsdlUrlInput.value.trim();
        if (!name || !url) return alert('Пожалуйста, введите название и URL');

        try {
            btnAddWsdl.innerText = 'Добавление...';
            btnAddWsdl.disabled = true;

            const res = await fetch('api.php?action=add_wsdl', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, url })
            });
            const data = await res.json();
            if (data.success) {
                wsdlNameInput.value = '';
                wsdlUrlInput.value = '';
                loadWsdls();
            } else {
                alert('Ошибка: ' + (data.error || 'Не удалось добавить WSDL'));
            }
        } catch (e) {
            alert('Ошибка сети при добавлении WSDL');
        } finally {
            btnAddWsdl.innerText = 'Добавить WSDL';
            btnAddWsdl.disabled = false;
        }
    });

    async function loadWsdls() {
        try {
            const res = await fetch('api.php?action=get_wsdls');
            wsdls = await res.json();
            renderWsdls();
        } catch(e) {
            console.error(e);
        }
    }

    function renderWsdls() {
        wsdlListEl.innerHTML = '';
        wsdls.forEach(wsdl => {
            const item = document.createElement('div');
            item.className = 'wsdl-item';
            
            const header = document.createElement('div');
            header.className = 'wsdl-header';
            
            const titleSpan = document.createElement('span');
            titleSpan.innerText = wsdl.name;
            titleSpan.className = 'wsdl-title';
            
            const actionsDiv = document.createElement('div');
            actionsDiv.style.display = 'flex';
            actionsDiv.style.gap = '8px';
            actionsDiv.style.alignItems = 'center';
            
            const deleteBtn = document.createElement('span');
            deleteBtn.innerHTML = '✕';
            deleteBtn.className = 'delete-wsdl-btn';
            deleteBtn.title = 'Удалить проект';
            deleteBtn.onclick = async (e) => {
                e.stopPropagation();
                if (confirm(`Вы уверены, что хотите удалить проект "${wsdl.name}" и все его сохраненные запросы?`)) {
                    await fetch('api.php?action=delete_wsdl', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: wsdl.id })
                    });
                    loadWsdls();
                    if (currentWsdlId === wsdl.id) {
                        currentWsdlId = null;
                        currentMethod = null;
                        currentWsdlUrl = null;
                        document.getElementById('current-method-title').innerText = 'Выберите метод в меню слева';
                        document.getElementById('saved-requests-list').innerHTML = '<span class="text-secondary">Здесь будут отображаться сохраненные запросы</span>';
                        requestEditor.setValue('');
                        responseEditor.setValue('');
                    }
                }
            };
            
            const expandIcon = document.createElement('small');
            expandIcon.innerText = '▼';
            
            actionsDiv.appendChild(deleteBtn);
            actionsDiv.appendChild(expandIcon);
            
            header.appendChild(titleSpan);
            header.appendChild(actionsDiv);
            
            header.onclick = () => toggleWsdl(wsdl.id, wsdl.url, item);

            const methodList = document.createElement('div');
            methodList.className = 'method-list';
            methodList.id = `methods-${wsdl.id}`;

            item.appendChild(header);
            item.appendChild(methodList);
            wsdlListEl.appendChild(item);
        });
    }

    async function toggleWsdl(id, url, itemEl) {
        // Toggle active class
        const isActive = itemEl.classList.contains('active');
        document.querySelectorAll('.wsdl-item').forEach(el => el.classList.remove('active'));
        if (!isActive) {
            itemEl.classList.add('active');
            
            // Load methods if not loaded
            const methodListEl = document.getElementById(`methods-${id}`);
            if (methodListEl.children.length === 0) {
                methodListEl.innerHTML = '<div class="method-item">Загрузка...</div>';
                try {
                    const res = await fetch(`api.php?action=parse_wsdl&id=${id}&url=${encodeURIComponent(url)}`);
                    const data = await res.json();
                    
                    if (data.success) {
                        methodListEl.innerHTML = '';
                        if (data.methods.length === 0) {
                            methodListEl.innerHTML = '<div class="method-item">Методы не найдены</div>';
                        }
                        data.methods.forEach(methodObj => {
                            const mItem = document.createElement('div');
                            mItem.className = 'method-item';
                            
                            // Style the count indicator nicely if > 0
                            if (methodObj.saved_count > 0) {
                                mItem.innerHTML = `${methodObj.name} <span style="margin-left:auto; background:rgba(59,130,246,0.2); color:#60a5fa; padding:2px 6px; border-radius:10px; font-size:11px;">${methodObj.saved_count}</span>`;
                            } else {
                                mItem.innerText = methodObj.name;
                            }
                            
                            mItem.onclick = (e) => {
                                e.stopPropagation();
                                document.querySelectorAll('.method-item').forEach(el => el.classList.remove('active'));
                                mItem.classList.add('active');
                                selectMethod(id, url, methodObj.name);
                            };
                            methodListEl.appendChild(mItem);
                        });
                    } else {
                        methodListEl.innerHTML = `<div class="method-item" style="color: #ef4444;" title="${data.error}">Ошибка парсинга</div>`;
                    }
                } catch (e) {
                    methodListEl.innerHTML = '<div class="method-item">Ошибка сети</div>';
                }
            }
        }
    }

    async function selectMethod(wsdlId, wsdlUrl, method) {
        currentWsdlId = wsdlId;
        currentWsdlUrl = wsdlUrl;
        currentMethod = method;
        currentSavedRequestName = '';
        currentMethodTitle.innerText = method;
        document.getElementById('request-name-display').innerText = '';
        
        btnSendRequest.disabled = false;
        btnSaveRequest.disabled = false;
        
        // Generate template
        try {
            const res = await fetch(`api.php?action=generate_template&url=${encodeURIComponent(wsdlUrl)}&method=${method}`);
            const data = await res.json();
            if (data.success) {
                requestEditor.setValue(data.template);
                setTimeout(() => requestEditor.refresh(), 100);
                responseEditor.setValue('');
            }
        } catch (e) {
            console.error('Error generating template', e);
        }

        loadSavedRequests();
    }

    btnSendRequest.addEventListener('click', async () => {
        const xml = requestEditor.getValue();
        if (!xml) return;

        btnSendRequest.innerText = 'Отправка...';
        btnSendRequest.disabled = true;

        try {
            const res = await fetch('api.php?action=send_request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: currentWsdlUrl, xml: xml })
            });
            const data = await res.json();
            if (data.success) {
                responseEditor.setValue(formatXml(data.response));
                setTimeout(() => responseEditor.refresh(), 100);
            } else {
                responseEditor.setValue(`Ошибка:\n${data.error}`);
            }
        } catch (e) {
            responseEditor.setValue(`Сетевая ошибка:\n${e.message}`);
        } finally {
            btnSendRequest.innerText = 'Отправить запрос';
            btnSendRequest.disabled = false;
        }
    });

    // Format XML utility
    function formatXml(xml) {
        if (!xml) return '';
        
        // Unescape heavy escaped XML strings and wrap in CDATA for readability (like SoapUI does)
        xml = xml.replace(/>\s*(&lt;[\s\S]*?)\s*</g, function(match, innerContent) {
            let unescaped = innerContent.replace(/&lt;/g, '<')
                                        .replace(/&gt;/g, '>')
                                        .replace(/&quot;/g, '"')
                                        .replace(/&apos;/g, "'")
                                        .replace(/&amp;/g, '&');
            return `><![CDATA[\n${unescaped}\n]]><`;
        });
        
        let formatted = '';
        let reg = /(>)(<)(\/*)/g;
        xml = xml.replace(reg, '$1\r\n$2$3');
        let pad = 0;
        xml.split('\r\n').forEach(function(node) {
            let indent = 0;
            if (node.match( /.+<\/\w[^>]*>$/ )) {
                indent = 0;
            } else if (node.match( /^<\/\w/ )) {
                if (pad != 0) {
                    pad -= 1;
                }
            } else if (node.match( /^<\w[^>]*[^\/]>.*$/ )) {
                indent = 1;
            } else {
                indent = 0;
            }
            let padding = '';
            for (let i = 0; i < pad; i++) {
                padding += '  ';
            }
            formatted += padding + node + '\r\n';
            pad += indent;
        });
        return formatted;
    }

    // Save functionality
    btnSaveRequest.addEventListener('click', () => {
        modal.classList.add('active');
        saveRequestNameInput.value = currentSavedRequestName;
        saveRequestNameInput.focus();
        saveRequestNameInput.select();
    });

    btnCancelSave.addEventListener('click', () => {
        modal.classList.remove('active');
        saveRequestNameInput.value = '';
    });

    btnConfirmSave.addEventListener('click', async () => {
        const name = saveRequestNameInput.value.trim();
        if (!name) return alert('Название обязательно');

        const request_xml = requestEditor.getValue();
        const response_xml = responseEditor.getValue();

        try {
            btnConfirmSave.innerText = 'Сохранение...';
            const res = await fetch('api.php?action=save_request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    wsdl_id: currentWsdlId,
                    method_name: currentMethod,
                    request_name: name,
                    request_xml: request_xml,
                    response_xml: response_xml
                })
            });
            const data = await res.json();
            if (data.success) {
                modal.classList.remove('active');
                currentSavedRequestName = name;
                saveRequestNameInput.value = '';
                loadSavedRequests();
                document.getElementById('request-name-display').innerText = `- ${name}`;
            }
        } catch (e) {
            alert('Ошибка при сохранении запроса');
        } finally {
            btnConfirmSave.innerText = 'Сохранить';
        }
    });

    async function loadSavedRequests() {
        if (!currentWsdlId || !currentMethod) return;
        
        const listEl = document.getElementById('saved-requests-list');
        listEl.innerHTML = 'Загрузка...';

        try {
            const res = await fetch(`api.php?action=get_saved_requests&wsdl_id=${currentWsdlId}&method_name=${encodeURIComponent(currentMethod)}`);
            const data = await res.json();
            
            listEl.innerHTML = '';
            if (data.length === 0) {
                listEl.innerHTML = '<span class="text-secondary">Нет сохраненных вариантов</span>';
                return;
            }

            data.forEach(req => {
                const tag = document.createElement('div');
                tag.className = 'saved-request-tag';
                tag.innerHTML = `<span>${req.request_name}</span>`;
                tag.onclick = () => {
                    currentSavedRequestName = req.request_name;
                    requestEditor.setValue(req.request_xml);
                    responseEditor.setValue(req.response_xml || '');
                    setTimeout(() => {
                        requestEditor.refresh();
                        responseEditor.refresh();
                    }, 100);
                    document.getElementById('request-name-display').innerText = `- ${req.request_name}`;
                };
                listEl.appendChild(tag);
            });
        } catch (e) {
            listEl.innerHTML = '<span class="text-secondary">Ошибка загрузки</span>';
        }
    }
});
