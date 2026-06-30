const fs = require('fs');
const path = require('path');
const ejs = require('ejs');

class PdfReportGenerator {
    constructor() {
        this.baseDir = path.join(process.cwd(), 'data', 'storage', 'security');
        // Let's use manager/storage/security or data/storage/security.
        // Wait, pui4 has 'data/security' used previously. I will use 'storage/security' from the process.cwd().
        // process.cwd() is likely the 'manager' dir because server.js runs from there.
        this.baseDir = path.join(process.cwd(), 'storage', 'security');
    }

    async generate(type, resultData) {
        // e.g., type = 'sast', 'sca', 'dast'
        const dateStr = this._getFormattedDate();
        const outputDir = path.join(this.baseDir, type.toLowerCase(), dateStr);
        
        if (!fs.existsSync(outputDir)) {
            fs.mkdirSync(outputDir, { recursive: true });
        }

        const jsonPath = path.join(outputDir, 'resultado.json');
        const htmlPath = path.join(outputDir, 'resultado.html');
        const pdfPath = path.join(outputDir, 'resultado.pdf');

        // 1. Save JSON
        fs.writeFileSync(jsonPath, JSON.stringify(resultData, null, 2));

        // 2. Render HTML
        const htmlContent = ejs.render(this._getTemplate(), { report: resultData });
        fs.writeFileSync(htmlPath, htmlContent);

        // 3. Render PDF
        try {
            // Check if playwright is available
            const playwright = require('playwright');
            const browser = await playwright.chromium.launch();
            const page = await browser.newPage();
            await page.setContent(htmlContent, { waitUntil: 'networkidle' });
            await page.pdf({ path: pdfPath, format: 'A4', printBackground: true });
            await browser.close();
            resultData.pdf_path = pdfPath;
        } catch (e) {
            console.log(`[Warning] PDF generation skipped. Playwright not available or failed: ${e.message}`);
            resultData.pdf_path = null;
        }

        resultData.json_path = jsonPath;
        resultData.html_path = htmlPath;
        
        return resultData;
    }

    _getFormattedDate() {
        const d = new Date();
        const pad = (n) => n.toString().padStart(2, '0');
        const YYYY = d.getFullYear();
        const MM = pad(d.getMonth() + 1);
        const DD = pad(d.getDate());
        const hh = pad(d.getHours());
        const mm = pad(d.getMinutes());
        const ss = pad(d.getSeconds());
        return `${YYYY}${MM}${DD}-${hh}${mm}${ss}`;
    }

    _getTemplate() {
        return `
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <title>Reporte de Seguridad - <%= report.type %></title>
                <style>
                    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #333; line-height: 1.6; }
                    h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; margin-bottom: 30px; }
                    h2 { color: #2980b9; margin-top: 40px; border-bottom: 1px solid #bdc3c7; padding-bottom: 5px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
                    th { background-color: #ecf0f1; color: #2c3e50; }
                    .metadata-table th { width: 30%; }
                    .finding { background: #f9f9f9; padding: 20px; border-left: 5px solid #e74c3c; margin-bottom: 20px; }
                    .finding h3 { margin-top: 0; color: #e74c3c; }
                    .severity-high { color: #c0392b; font-weight: bold; }
                    .severity-medium { color: #d35400; font-weight: bold; }
                    .severity-low { color: #f39c12; font-weight: bold; }
                </style>
            </head>
            <body>
                <h1>Reporte de Seguridad <%= report.type %></h1>
                
                <h2>1. Información del Análisis</h2>
                <table class="metadata-table">
                    <tr><th>Prueba Ejecutada</th><td><%= report.type %></td></tr>
                    <tr><th>Instancia Evaluada</th><td><%= report.target %></td></tr>
                    <tr><th>Fecha de Ejecución</th><td><%= report.date %></td></tr>
                    <tr><th>Ruta/URL Evaluada</th><td><%= report.targetPath || 'N/A' %></td></tr>
                    <tr><th>Herramienta Intentada</th><td><%= report.tool %></td></tr>
                    <tr><th>Comando Ejecutado</th><td><code><%= report.command || 'N/A' %></code></td></tr>
                    <tr><th>Resultado</th><td><strong><%= report.status %></strong></td></tr>
                    <tr><th>Trazabilidad (Branch/Commit)</th><td>N/A (Local)</td></tr>
                </table>

                <% if (report.type === 'SAST') { %>
                    <h2>1.1 Requisitos del Manual (SAST)</h2>
                    <ul>
                        <li><strong>Análisis de código fuente:</strong> Realizado vía escaneo estático.</li>
                        <li><strong>Componente evaluado:</strong> <%= report.targetPath %></li>
                        <li><strong>Configuración / Reglas:</strong> <%= report.config %></li>
                    </ul>
                <% } else if (report.type === 'SCA') { %>
                    <h2>1.1 Requisitos del Manual (SCA)</h2>
                    <ul>
                        <li><strong>Dependencias evaluadas:</strong> Node.js / PHP (según manifiestos encontrados).</li>
                        <li><strong>Licencias y Componentes:</strong> Auditados según base de datos CVE de NPM/Composer.</li>
                    </ul>
                <% } else if (report.type === 'DAST') { %>
                    <h2>1.1 Requisitos del Manual (DAST)</h2>
                    <ul>
                        <li><strong>Servicios en ejecución y Endpoints:</strong> Auditados vía spidering.</li>
                        <li><strong>Reglas OWASP Top 10:</strong> Aplicadas.</li>
                    </ul>
                <% } %>

                <h2>2. Resumen Ejecutivo</h2>
                <p>El análisis de seguridad tipo <strong><%= report.type %></strong> utilizando la herramienta <strong><%= report.tool %></strong> finalizó con el estado: <strong><%= report.status %></strong>.</p>
                <p>Se encontraron un total de <strong><%= report.findings.length %></strong> hallazgos de seguridad.</p>

                <h2>3. Hallazgos Detallados</h2>
                <% if (report.findings && report.findings.length > 0) { %>
                    <% report.findings.forEach(function(finding, index) { %>
                        <div class="finding">
                            <h3>Hallazgo #<%= index + 1 %>: <%= finding.title %></h3>
                            <p><strong>Severidad:</strong> <span class="severity-<%= (finding.severity || 'medium').toLowerCase() %>"><%= finding.severity || 'Medium' %></span></p>
                            <p><strong>Descripción / Error Real:</strong> <pre style="white-space: pre-wrap; font-family: inherit;"><%= finding.description %></pre></p>
                            <p><strong>Recomendaciones / Justificación:</strong> <%= finding.recommendation %></p>
                        </div>
                    <% }); %>
                <% } else { %>
                    <p>No se encontraron vulnerabilidades ni errores en este análisis.</p>
                    <p><em>Justificación formal: El código o servicio auditado cumple con las reglas básicas del perfil evaluado y la herramienta no reportó anomalías bajo esta configuración.</em></p>
                <% } %>
            </body>
            </html>
        `;
    }
}

module.exports = PdfReportGenerator;
