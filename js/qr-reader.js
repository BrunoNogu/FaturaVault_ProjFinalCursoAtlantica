// Leitura de QR codes e lógica do formulário de nova fatura
// Usa PDF.js para renderizar o PDF e jsQR para detetar o código QR

pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

var vatLineCounter = 0;

// Quando o utilizador seleciona um novo ficheiro, limpa tudo e tenta ler o QR
document.getElementById('file').addEventListener('change', function() {
    var file = this.files[0];
    if (!file || file.type !== 'application/pdf') return;

    // Limpa todos os campos quando se muda de ficheiro
    var fieldsToReset = ['supplier_vat', 'supplier_name', 'buyer_vat', 'document_type',
        'document_number', 'document_date', 'atcud', 'qr_data'];
    fieldsToReset.forEach(function(id) {
        var f = document.getElementById(id);
        if (f) { f.value = ''; f.readOnly = false; f.classList.remove('campo-bloqueado'); }
    });
    document.getElementById('category').value = '';
    document.getElementById('vat-lines').innerHTML = '';
    document.getElementById('btn-add-vat').style.display = '';
    document.getElementById('total').value = '0.00';
    document.getElementById('total_vat').value = '0.00';
    document.getElementById('azure-zone').style.display = 'none';
    var vatStatus = document.getElementById('vat-status');
    if (vatStatus) { vatStatus.textContent = ''; vatStatus.className = 'campo-estado'; }
    vatLineCounter = 0;

    var status = document.getElementById('qr-status');
    status.style.display = 'block';
    status.className = 'mensagem info';
    status.textContent = 'A ler QR code do PDF...';

    var reader = new FileReader();
    reader.onload = function(e) {
        readQRFromPDF(new Uint8Array(e.target.result));
    };
    reader.readAsArrayBuffer(file);
});

// Lê o PDF e tenta encontrar um QR code, página a página (começa pela última)
function readQRFromPDF(pdfData) {
    var status = document.getElementById('qr-status');

    pdfjsLib.getDocument({ data: pdfData }).promise.then(function(pdf) {
        var pages = [];
        for (var i = pdf.numPages; i >= 1; i--) {
            pages.push(i);
        }

        // Percorre as páginas uma a uma, para quando encontrar o QR
        function tryNext(index) {
            if (index >= pages.length) return Promise.resolve(null);
            return tryReadQRFromPage(pdf, pages[index]).then(function(result) {
                if (result) return result;
                return tryNext(index + 1);
            });
        }

        tryNext(0).then(function(qrData) {
            pdf.destroy(); // Liberta memória do PDF

            if (qrData) {
                status.className = 'mensagem sucesso';
                status.textContent = 'QR code lido com sucesso! Campos preenchidos automaticamente.';
                fillFromQR(qrData);
                // Se "analisar sempre" estiver ativo, enviar também para Azure para extrair texto
                if (typeof azureAlwaysAnalyze !== 'undefined' && azureAlwaysAnalyze && typeof hasAzure !== 'undefined' && hasAzure) {
                    status.textContent += ' A extrair conteúdo com Azure AI...';
                    analyzeWithAzure(true); // true = apenas extrair texto, não sobrescrever campos do QR
                }
            } else {
                status.className = 'mensagem erro';
                status.textContent = 'Não foi possível encontrar um QR code no PDF.';
                if (typeof hasAzure !== 'undefined' && hasAzure) {
                    if (typeof azureAutoAnalyze !== 'undefined' && azureAutoAnalyze) {
                        status.className = 'mensagem';
                        status.textContent = 'QR code não encontrado. A analisar com Azure AI...';
                        analyzeWithAzure();
                    } else {
                        document.getElementById('azure-zone').style.display = 'block';
                    }
                } else {
                    status.textContent += ' Preencha os campos manualmente.';
                }
            }
        });
    }).catch(function(err) {
        status.className = 'mensagem erro';
        status.textContent = 'Erro ao ler o PDF: ' + err.message;
    });
}

// Tenta ler o QR de uma página, testando várias escalas e binarização
function tryReadQRFromPage(pdf, pageNumber) {
    return pdf.getPage(pageNumber).then(function(page) {
        var scales = [3, 4, 2, 5]; // Escalas a tentar (diferentes resoluções)

        // Filtro preto e branco - melhora a leitura em documentos digitalizados
        function binarize(imageData, threshold) {
            var data = new Uint8ClampedArray(imageData.data);
            for (var i = 0; i < data.length; i += 4) {
                var gray = data[i] * 0.299 + data[i+1] * 0.587 + data[i+2] * 0.114;
                var val = gray > threshold ? 255 : 0;
                data[i] = data[i+1] = data[i+2] = val;
            }
            return new ImageData(data, imageData.width, imageData.height);
        }

        function tryScale(index) {
            if (index >= scales.length) return null;

            var viewport = page.getViewport({ scale: scales[index] });
            var canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            var ctx = canvas.getContext('2d');

            return page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function() {
                var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

                // Liberta a memória do canvas
                canvas.width = 0;
                canvas.height = 0;

                // Tenta com a imagem original
                var code = jsQR(imageData.data, imageData.width, imageData.height, {
                    inversionAttempts: 'attemptBoth'
                });
                if (code && code.data) return code.data;

                // Se não encontrou, tenta com a imagem binarizada (mais contraste)
                var bin = binarize(imageData, 128);
                code = jsQR(bin.data, bin.width, bin.height, {
                    inversionAttempts: 'attemptBoth'
                });
                if (code && code.data) return code.data;

                return tryScale(index + 1);
            });
        }

        return tryScale(0);
    });
}

// Adiciona uma linha de IVA ao formulário (valor bruto, taxa, valor do IVA)
function addVatLine(baseValue, vatRate, vatValue, locked) {
    vatLineCounter++;
    var container = document.getElementById('vat-lines');
    var row = document.createElement('div');
    row.className = 'linha-iva';

    var bv = (baseValue !== undefined && baseValue !== null) ? parseFloat(baseValue).toFixed(2) : '0.00';
    var vv = (vatValue !== undefined && vatValue !== null) ? parseFloat(vatValue).toFixed(2) : '0.00';
    if (vatRate === undefined || vatRate === null) vatRate = 23;

    var readonlyAttr = locked ? 'readonly' : '';
    var lockedClass = locked ? 'campo-bloqueado' : '';
    var disabledAttr = locked ? 'disabled' : '';
    var num = vatLineCounter;

    row.innerHTML =
        '<div class="linha-iva-campos">' +
            '<div class="campo">' +
                '<label>Valor bruto</label>' +
                '<input type="number" step="0.01" class="iva-valor-bruto ' + lockedClass + '" value="' + bv + '" ' + readonlyAttr + ' oninput="calculateVatLine(this.closest(\'.linha-iva\'))">' +
            '</div>' +
            '<div class="campo">' +
                '<label>Tipo de IVA</label>' +
                '<select class="iva-tipo ' + lockedClass + '" ' + disabledAttr + ' onchange="calculateVatLine(this.closest(\'.linha-iva\'))">' +
                    '<option value="0"' + (vatRate === 0 ? ' selected' : '') + '>0% (Isenta)</option>' +
                    '<option value="6"' + (vatRate === 6 ? ' selected' : '') + '>6% (Reduzida)</option>' +
                    '<option value="13"' + (vatRate === 13 ? ' selected' : '') + '>13% (Intermédia)</option>' +
                    '<option value="23"' + (vatRate === 23 ? ' selected' : '') + '>23% (Normal)</option>' +
                '</select>' +
            '</div>' +
            '<div class="campo">' +
                '<label>Valor do IVA</label>' +
                '<input type="number" step="0.01" class="iva-valor-iva campo-bloqueado" value="' + vv + '" readonly>' +
            '</div>' +
            (locked ? '' : '<button type="button" class="btn btn-pequeno btn-perigo btn-remover-iva" onclick="removeVatLine(this)">Remover</button>') +
        '</div>' +
        '<span class="linha-iva-numero">Linha de IVA ' + num + '</span>';

    container.appendChild(row);
}

function removeVatLine(btn) {
    btn.closest('.linha-iva').remove();
    recalculateTotals();
}

function calculateVatLine(row) {
    var baseValue = parseFloat(row.querySelector('.iva-valor-bruto').value) || 0;
    var rate = parseFloat(row.querySelector('.iva-tipo').value) || 0;
    row.querySelector('.iva-valor-iva').value = (baseValue * rate / 100).toFixed(2);
    recalculateTotals();
}

// Recalcula os totais a partir de todas as linhas de IVA
function recalculateTotals() {
    var rows = document.querySelectorAll('.linha-iva');
    var totalAmount = 0;
    var totalVat = 0;

    rows.forEach(function(row) {
        var bv = parseFloat(row.querySelector('.iva-valor-bruto').value) || 0;
        var vv = parseFloat(row.querySelector('.iva-valor-iva').value) || 0;
        totalAmount += bv + vv;
        totalVat += vv;
    });

    document.getElementById('total').value = totalAmount.toFixed(2);
    document.getElementById('total_vat').value = totalVat.toFixed(2);
}

// Adiciona o prefixo PT se o NIF só tiver números
function ensureCountryCode(vat) {
    if (/^[0-9]+$/.test(vat)) return 'PT' + vat;
    return vat;
}

// Antes de submeter, agrupa os valores de IVA por taxa nos campos hidden
function prepareSubmission() {
    if (azurePending) {
        alert('Aguarde a conclusão da análise Azure AI.');
        return false;
    }
    var supplierVat = document.getElementById('supplier_vat').value.trim();
    if (!/^[A-Za-z]{2}/.test(supplierVat)) {
        alert('O NIF do fornecedor deve incluir o código do país (ex: PT123456789).');
        document.getElementById('supplier_vat').focus();
        return false;
    }

    var rows = document.querySelectorAll('.linha-iva');
    var bases = { '0': 0, '6': 0, '13': 0, '23': 0 };
    var vats = { '6': 0, '13': 0, '23': 0 };

    rows.forEach(function(row) {
        var bv = parseFloat(row.querySelector('.iva-valor-bruto').value) || 0;
        var vv = parseFloat(row.querySelector('.iva-valor-iva').value) || 0;
        var rate = row.querySelector('.iva-tipo').value;
        bases[rate] = (bases[rate] || 0) + bv;
        if (rate !== '0') vats[rate] = (vats[rate] || 0) + vv;
    });

    document.getElementById('base_exempt').value = bases['0'].toFixed(2);
    document.getElementById('base_reduced').value = bases['6'].toFixed(2);
    document.getElementById('vat_reduced').value = vats['6'].toFixed(2);
    document.getElementById('base_intermediate').value = bases['13'].toFixed(2);
    document.getElementById('vat_intermediate').value = vats['13'].toFixed(2);
    document.getElementById('base_standard').value = bases['23'].toFixed(2);
    document.getElementById('vat_standard').value = vats['23'].toFixed(2);

    return true;
}

// Consulta o VIES para validar o NIF e obter o nome do fornecedor
function lookupVIES(vat) {
    if (!vat || vat.length < 4) return;

    var status = document.getElementById('vat-status');
    var submitBtn = document.querySelector('#formInvoice button[type="submit"]');
    status.textContent = 'A consultar VIES...';
    status.className = 'campo-estado info';
    submitBtn.disabled = true;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'lookup_vat.php?vat=' + encodeURIComponent(vat));

    xhr.onload = function() {
        submitBtn.disabled = false;
        try {
            var response = JSON.parse(xhr.responseText);
        } catch (e) {
            status.textContent = '';
            return;
        }

        var nameField = document.getElementById('supplier_name');
        if (response.valid) {
            var name = (response.name || '').replace(/^[-\s]+$/, '').trim();
            if (name) {
                nameField.value = name;
                nameField.readOnly = true;
                nameField.classList.add('campo-bloqueado');
            } else {
                nameField.readOnly = false;
                nameField.classList.remove('campo-bloqueado');
            }
            var source = response.source === 'local' ? 'BD' : 'VIES';
            status.textContent = 'NIF válido — ' + source + (name ? '' : ' — preencha o nome manualmente');
            status.className = 'campo-estado valido';
        } else {
            nameField.readOnly = false;
            nameField.classList.remove('campo-bloqueado');
            status.textContent = 'NIF não encontrado no VIES. Preencha o nome manualmente.';
            status.className = 'campo-estado invalido';
        }

        if (response.last_category) {
            var catSelect = document.getElementById('category');
            for (var i = 0; i < catSelect.options.length; i++) {
                if (catSelect.options[i].value === response.last_category) {
                    catSelect.value = response.last_category;
                    break;
                }
            }
        }
    };

    xhr.onerror = function() {
        submitBtn.disabled = false;
        status.textContent = 'Erro ao consultar VIES';
        status.className = 'campo-estado invalido';
    };

    xhr.send();
}

// Verifica se o NIF do adquirente corresponde ao da empresa configurada
function checkBuyerVat(buyerVat) {
    if (typeof companyVat === 'undefined' || companyVat === '') return;
    var cleanBuyer = buyerVat.replace(/[^0-9]/g, '');
    var cleanCompany = companyVat.replace(/[^0-9]/g, '');
    if (cleanBuyer !== '' && cleanCompany !== '' && cleanBuyer !== cleanCompany) {
        alert('Atenção: O NIF do adquirente (' + buyerVat + ') não corresponde ao NIF da empresa configurado (' + companyVat + ').');
    }
}

// Quando o campo de NIF perde o foco, adiciona PT se precisar e consulta o VIES
document.getElementById('supplier_vat').addEventListener('blur', function() {
    if (!this.readOnly) {
        var val = this.value.trim();
        if (val !== '' && /^[0-9]+$/.test(val)) {
            this.value = 'PT' + val;
        }
        lookupVIES(this.value);
    }
});

// Bloqueia um campo e define o seu valor (usado pelo QR)
function lockField(id, value) {
    var field = document.getElementById(id);
    if (!field) return;
    field.value = value;
    field.readOnly = true;
    field.classList.add('campo-bloqueado');
}

// Alguns emissores colocam os valores I2-I8 em cêntimos, apesar de O estar em euros
function getQRAmountScale(fields) {
    var documentTotal = parseFloat(fields['O']) || 0;
    if (documentTotal <= 0) return 1;

    var calculatedTotal = 0;
    var amountFields = ['I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8'];
    for (var i = 0; i < amountFields.length; i++) {
        calculatedTotal += parseFloat(fields[amountFields[i]]) || 0;
    }

    return Math.abs(calculatedTotal / 100 - documentTotal) < 0.02 ? 100 : 1;
}

// Preenche o formulário com os dados extraídos do QR code
// Formato do QR: A:NIF_FORNECEDOR*B:NIF_ADQUIRENTE*D:TIPO*F:DATA*G:NUMERO*H:ATCUD*I2:BASE_ISENTA*...
function fillFromQR(qrData) {
    document.getElementById('qr_data').value = qrData;

    var fields = {};
    var parts = qrData.split('*');
    for (var i = 0; i < parts.length; i++) {
        var pos = parts[i].indexOf(':');
        if (pos !== -1) {
            fields[parts[i].substring(0, pos)] = parts[i].substring(pos + 1);
        }
    }

    if (fields['A']) {
        var supplierVat = ensureCountryCode(fields['A']);
        lockField('supplier_vat', supplierVat);
        lookupVIES(supplierVat);
    }
    if (fields['B']) {
        var buyerVat = ensureCountryCode(fields['B']);
        lockField('buyer_vat', buyerVat);
        checkBuyerVat(buyerVat);
    }
    if (fields['D']) lockField('document_type', fields['D']);
    if (fields['G']) lockField('document_number', fields['G']);
    if (fields['H']) lockField('atcud', fields['H']);

    if (fields['F'] && fields['F'].length === 8) {
        var d = fields['F'];
        lockField('document_date', d.substring(0,4) + '-' + d.substring(4,6) + '-' + d.substring(6,8));
    }

    document.getElementById('vat-lines').innerHTML = '';
    vatLineCounter = 0;

    var amountScale = getQRAmountScale(fields);
    if (fields['I2'] && parseFloat(fields['I2']) > 0)
        addVatLine(parseFloat(fields['I2']) / amountScale, 0, 0, true);
    if (fields['I3'] && parseFloat(fields['I3']) > 0)
        addVatLine(parseFloat(fields['I3']) / amountScale, 6, (parseFloat(fields['I4']) || 0) / amountScale, true);
    if (fields['I5'] && parseFloat(fields['I5']) > 0)
        addVatLine(parseFloat(fields['I5']) / amountScale, 13, (parseFloat(fields['I6']) || 0) / amountScale, true);
    if (fields['I7'] && parseFloat(fields['I7']) > 0)
        addVatLine(parseFloat(fields['I7']) / amountScale, 23, (parseFloat(fields['I8']) || 0) / amountScale, true);

    document.getElementById('btn-add-vat').style.display = 'none';

    recalculateTotals();
}

// Envia o PDF para o Azure AI e preenche os campos com o resultado
// textOnly = true: apenas guarda o texto extraído, não sobrescreve campos do QR
var azurePending = false;
function analyzeWithAzure(textOnly) {
    var fileInput = document.getElementById('file');
    if (!fileInput.files || !fileInput.files[0]) {
        alert('Selecione um ficheiro PDF primeiro.');
        return;
    }

    azurePending = true;
    var status = document.getElementById('qr-status');
    var btnAzure = document.getElementById('btn-azure');

    status.style.display = 'block';
    status.className = 'mensagem info';
    if (textOnly) {
        status.textContent = 'A extrair conteúdo com Azure AI...';
    } else {
        status.textContent = 'A enviar para Azure AI... Isto pode demorar alguns segundos.';
        btnAzure.disabled = true;
        btnAzure.textContent = 'A analisar...';
    }

    var formData = new FormData();
    formData.append('file', fileInput.files[0]);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'analyze.php');

    xhr.onload = function() {
        if (!textOnly) {
            btnAzure.disabled = false;
            btnAzure.textContent = 'Analisar com Azure AI';
        }

        try {
            var response = JSON.parse(xhr.responseText);
        } catch (e) {
            azurePending = false;
            if (!textOnly) {
                status.className = 'mensagem erro';
                status.textContent = 'Erro ao processar resposta do servidor.';
            }
            return;
        }

        if (response.error) {
            azurePending = false;
            if (!textOnly) {
                status.className = 'mensagem erro';
                status.textContent = response.error;
            }
            return;
        }

        // Guardar texto extraído no campo hidden
        if (response.extracted_text) {
            var hiddenText = document.getElementById('extracted_text');
            if (hiddenText) hiddenText.value = response.extracted_text;
        }
        azurePending = false;

        if (textOnly) {
            status.className = 'mensagem sucesso';
            status.textContent = 'QR code lido com sucesso! Conteúdo extraído com Azure AI.';
            return;
        }

        if (response.success && response.fields) {
            status.className = 'mensagem sucesso';
            status.textContent = 'Análise concluída! Verifique e corrija os campos se necessário.';
            document.getElementById('azure-zone').style.display = 'none';

            var f = response.fields;
            if (f.supplier_vat) {
                document.getElementById('supplier_vat').value = f.supplier_vat;
                lookupVIES(f.supplier_vat);
            }
            if (f.buyer_vat) {
                document.getElementById('buyer_vat').value = f.buyer_vat;
                checkBuyerVat(f.buyer_vat);
            }
            if (f.document_number) document.getElementById('document_number').value = f.document_number;
            if (f.document_date)   document.getElementById('document_date').value = f.document_date;

            document.getElementById('vat-lines').innerHTML = '';
            vatLineCounter = 0;

            // Se o Azure devolveu linhas de IVA agrupadas por taxa, usa-as
            if (f.vat_lines && f.vat_lines.length > 0) {
                for (var i = 0; i < f.vat_lines.length; i++) {
                    addVatLine(f.vat_lines[i].base, f.vat_lines[i].rate, f.vat_lines[i].vat, false);
                }
            } else {
                // Fallback: calcula a taxa a partir dos totais
                var total = parseFloat(f.total || 0);
                var vat = parseFloat(f.total_vat || 0);
                var base = total > 0 ? total - vat : parseFloat(f.base_standard || 0);
                var rate = 23;
                if (vat === 0 || base === 0) {
                    rate = 0;
                } else {
                    var pct = Math.round(vat / base * 100);
                    if (pct <= 2) rate = 0;
                    else if (pct <= 9) rate = 6;
                    else if (pct <= 18) rate = 13;
                    else rate = 23;
                }
                addVatLine(base > 0 ? base : 0, rate, vat > 0 ? vat : 0, false);
            }
            recalculateTotals();
        }
    };

    xhr.onerror = function() {
        azurePending = false;
        if (!textOnly) {
            btnAzure.disabled = false;
            btnAzure.textContent = 'Analisar com Azure AI';
            status.className = 'mensagem erro';
            status.textContent = 'Erro de ligação ao servidor.';
        }
    };

    xhr.send(formData);
}
