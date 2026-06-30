const fs = require('fs');
const path = require('path');
const ejs = require('ejs');
const crypto = require('crypto');
const { execSync } = require('child_process');
const ToolRegistry = require('../engine/ToolRegistry');

class ReportGenerator {
    constructor(storageManager) {
        this.storageManager = storageManager;
    }

    async generateReports(auditId, auditData, dateObj) {
        const auditDir = this.storageManager.getAuditDirectory(auditId, dateObj);
        
        try {
            // Defensive directory creation
            if (!fs.existsSync(auditDir)) {
                fs.mkdirSync(auditDir, { recursive: true });
            }
            const logsDir = path.join(auditDir, 'logs');
            if (!fs.existsSync(logsDir)) {
                fs.mkdirSync(logsDir, { recursive: true });
            }

            // Render HTML from strings directly without writing to /views
            const execHtml = ejs.render(this._getBasicTemplate('Executive Report'), { audit: auditData });
            const techHtml = ejs.render(this._getBasicTemplate('Technical Report'), { audit: auditData });

            // Write HTML files
            const execHtmlPath = path.join(auditDir, 'executive-report.html');
            const techHtmlPath = path.join(auditDir, 'technical-report.html');
            fs.writeFileSync(execHtmlPath, execHtml);
            fs.writeFileSync(techHtmlPath, techHtml);

            const filesToManifest = [
                'executive-report.html',
                'technical-report.html'
            ];

            // Generate PDFs if Playwright is available
            const execPdfPath = path.join(auditDir, 'executive-report.pdf');
            const techPdfPath = path.join(auditDir, 'technical-report.pdf');
            
            try {
                if (ToolRegistry.isToolAvailable('playwright')) {
                    const { chromium } = require('playwright');
                    await this._htmlToPdf(chromium, execHtml, execPdfPath);
                    await this._htmlToPdf(chromium, techHtml, techPdfPath);
                    filesToManifest.push('executive-report.pdf', 'technical-report.pdf');
                } else {
                    console.log(`[Warning] Playwright not available, skipping PDF generation for Audit ${auditId}.`);
                    fs.writeFileSync(path.join(auditDir, 'logs', 'tool-missing.log'), "Auditoría incompleta por herramienta faltante: Playwright/Chromium no instalado.\n", { flag: 'a' });
                }
            } catch (e) {
                console.log(`[Warning] Error launching Playwright, skipping PDF for Audit ${auditId}:`, e.message);
                fs.writeFileSync(path.join(auditDir, 'logs', 'tool-missing.log'), "Auditoría incompleta por herramienta faltante: Playwright/Chromium no instalado.\n", { flag: 'a' });
            }

            // Update Metadata
            const metadataPath = path.join(auditDir, 'metadata.json');
            let metadata = { tools: [] };
            if (fs.existsSync(metadataPath)) {
                metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
            }
            
            metadata.audit_id = auditId;
            metadata.fecha = dateObj.toISOString();
            metadata.instancia = auditData.instance_name || 'N/A';
            metadata.profile = auditData.profile || 'Unknown';
            metadata.herramientas_utilizadas = auditData.toolsUsed || [];
            metadata.versiones = auditData.toolVersions || {};
            metadata.duracion = auditData.durationMs ? `${auditData.durationMs} ms` : 'N/A';
            metadata.status = auditData.status || 'Finished';
            if (metadata.resultado) delete metadata.resultado;
            
            fs.writeFileSync(metadataPath, JSON.stringify(metadata, null, 2));
            filesToManifest.push('metadata.json');
            
            if (fs.existsSync(path.join(auditDir, 'logs', 'tool-missing.log'))) {
                filesToManifest.push('logs/tool-missing.log');
            }

            // Generate Manifest with SHA256
            const manifest = {
                version: '1.0',
                audit_id: auditId,
                generated_at: new Date().toISOString(),
                files: {}
            };
            
            for (const file of filesToManifest) {
                const filePath = path.join(auditDir, file);
                if (fs.existsSync(filePath)) {
                    manifest.files[file] = this._calculateSha256(filePath);
                }
            }
            
            fs.writeFileSync(path.join(auditDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
            
            // Final Archive (use system commands, fallback to tar)
            const packagePath = await this._createSystemPackage(auditDir);
            
            if (!packagePath) {
                console.log(`[Warning] Package generation failed (zip/tar missing or failed) for Audit ${auditId}. Evidence is valid as a folder.`);
            }

            return packagePath;
        } catch (error) {
            console.error(`[Error] Failed to generate complete reports for Audit ${auditId}:`, error);
            const zipPath = path.join(auditDir, 'security-report.zip');
            const tarPath = path.join(auditDir, 'security-report.tar.gz');
            if (fs.existsSync(zipPath)) fs.unlinkSync(zipPath); // Cleanup corrupted ZIP
            if (fs.existsSync(tarPath)) fs.unlinkSync(tarPath); // Cleanup corrupted TAR
            throw error; // Propagate to mark evidence as incomplete
        }
    }

    _calculateSha256(filePath) {
        const fileBuffer = fs.readFileSync(filePath);
        const hashSum = crypto.createHash('sha256');
        hashSum.update(fileBuffer);
        return hashSum.digest('hex');
    }

    async _htmlToPdf(chromium, htmlContent, outputPath) {
        const browser = await chromium.launch();
        const page = await browser.newPage();
        await page.setContent(htmlContent, { waitUntil: 'networkidle' });
        await page.pdf({ path: outputPath, format: 'A4', printBackground: true });
        await browser.close();
    }

    async _createSystemPackage(sourceDir) {
        const zipPath = path.join(sourceDir, 'security-report.zip');
        const tarPath = path.join(sourceDir, 'security-report.tar.gz');
        
        try {
            // 1. Try ZIP
            execSync('zip -r security-report.zip . -x "security-report.*"', { cwd: sourceDir, stdio: 'pipe' });
            if (fs.existsSync(zipPath) && fs.statSync(zipPath).size > 0) {
                return zipPath;
            }
        } catch (e) {
            console.log(`[Warning] zip command failed in ${sourceDir}:`, e.message || e.stderr?.toString());
        }

        try {
            // 2. Fallback to TAR
            // Create tar in a temporary directory to avoid "file changed as we read it"
            const os = require('os');
            const tmpTarPath = path.join(os.tmpdir(), `security-report-${Date.now()}.tar.gz`);
            
            execSync(`tar --exclude="security-report.*" -czf ${tmpTarPath} -C ${sourceDir} .`, { stdio: 'pipe' });
            
            if (fs.existsSync(tmpTarPath) && fs.statSync(tmpTarPath).size > 0) {
                // Move from tmp to target directory
                fs.copyFileSync(tmpTarPath, tarPath);
                fs.unlinkSync(tmpTarPath);
                return tarPath;
            }
        } catch (e) {
            console.log(`[Warning] tar command failed in ${sourceDir}:`, e.message || e.stderr?.toString());
        }

        // 3. Mark as missing if both fail
        return null;
    }

    _getBasicTemplate(title) {
        return `
            <!DOCTYPE html>
            <html>
            <head>
                <title>${title}</title>
                <style>
                    body { font-family: sans-serif; padding: 40px; color: #333; }
                    h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
                    h2 { color: #2980b9; margin-top: 30px; }
                    .section { background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                    .missing { color: #e74c3c; font-style: italic; }
                    .metadata { background: #ecf0f1; padding: 15px; border-radius: 5px; }
                </style>
            </head>
            <body>
                <h1>${title}</h1>
                <div class="metadata">
                    <p><strong>Audit ID:</strong> <%= audit.id || 'N/A' %></p>
                    <p><strong>Date:</strong> <%= audit.date || new Date().toISOString() %></p>
                    <p><strong>Status:</strong> <%= audit.status || 'Finished' %></p>
                    <p><strong>Total Vulnerabilities:</strong> <%= audit.vulnerabilities_count || 0 %></p>
                </div>

                <h2>1. Static Application Security Testing (SAST)</h2>
                <div class="section">
                    <% if (audit.toolsUsed && audit.toolsUsed.includes('SAST')) { %>
                        <p>Análisis de código estático completado.</p>
                    <% } else { %>
                        <p class="missing">No ejecutado por herramienta faltante.</p>
                    <% } %>
                </div>

                <h2>2. Software Composition Analysis (SCA)</h2>
                <div class="section">
                    <% if (audit.toolsUsed && audit.toolsUsed.includes('SCA')) { %>
                        <p>Análisis de dependencias de terceros completado.</p>
                    <% } else { %>
                        <p class="missing">No ejecutado por herramienta faltante.</p>
                    <% } %>
                </div>

                <h2>3. Dynamic Application Security Testing (DAST)</h2>
                <div class="section">
                    <% if (audit.toolsUsed && audit.toolsUsed.includes('DAST')) { %>
                        <p>Análisis dinámico de instancia en ejecución completado.</p>
                    <% } else { %>
                        <p class="missing">No ejecutado por herramienta faltante.</p>
                    <% } %>
                </div>
            </body>
            </html>
        `;
    }
}

module.exports = ReportGenerator;
