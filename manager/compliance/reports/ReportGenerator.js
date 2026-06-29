const fs = require('fs');
const path = require('path');
const ejs = require('ejs');
const crypto = require('crypto');
const archiver = require('archiver');
const ToolRegistry = require('../engine/ToolRegistry');

class ReportGenerator {
    constructor(storageManager) {
        this.storageManager = storageManager;
        this.templatesDir = path.join(__dirname, '..', '..', 'views', 'compliance', 'reports');
        if (!fs.existsSync(this.templatesDir)) {
            fs.mkdirSync(this.templatesDir, { recursive: true });
        }
    }

    async generateReports(auditId, auditData, dateObj) {
        const auditDir = this.storageManager.getAuditDirectory(auditId, dateObj);
        
        // Ensure templates exist
        const execTemplatePath = path.join(this.templatesDir, 'executive.ejs');
        const techTemplatePath = path.join(this.templatesDir, 'technical.ejs');
        
        this._ensureBasicTemplate(execTemplatePath, 'Executive Report');
        this._ensureBasicTemplate(techTemplatePath, 'Technical Report');

        // Render HTML
        const execHtml = await ejs.renderFile(execTemplatePath, { audit: auditData });
        const techHtml = await ejs.renderFile(techTemplatePath, { audit: auditData });

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
        metadata.resultado = auditData.status || 'Finished';
        
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
        
        // Final Zip (do not hash the zip inside the manifest, just add it to folder)
        const zipPath = path.join(auditDir, 'security-report.zip');
        await this._createZip(auditDir, zipPath);

        return zipPath;
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

    _createZip(sourceDir, outPath) {
        return new Promise((resolve, reject) => {
            const output = fs.createWriteStream(outPath);
            const archive = archiver('zip', { zlib: { level: 9 } });

            output.on('close', () => resolve());
            archive.on('error', (err) => reject(err));

            archive.pipe(output);

            // Append all files except the zip itself
            fs.readdirSync(sourceDir).forEach(file => {
                if (file !== 'security-report.zip') {
                    const fullPath = path.join(sourceDir, file);
                    if (fs.statSync(fullPath).isDirectory()) {
                        archive.directory(fullPath, file);
                    } else {
                        archive.file(fullPath, { name: file });
                    }
                }
            });

            archive.finalize();
        });
    }

    _ensureBasicTemplate(templatePath, title) {
        if (!fs.existsSync(templatePath)) {
            fs.writeFileSync(templatePath, `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${title}</title>
                    <style>
                        body { font-family: sans-serif; padding: 40px; }
                        h1 { color: #2c3e50; }
                    </style>
                </head>
                <body>
                    <h1>${title}</h1>
                    <p>Audit ID: <%= audit.id || 'N/A' %></p>
                    <p>Date: <%= audit.date || new Date().toISOString() %></p>
                    <h2>Findings Summary</h2>
                    <p>Vulnerabilities: <%= audit.vulnerabilities_count || 0 %></p>
                    <p>Status: <%= audit.status || 'Finished' %></p>
                </body>
                </html>
            `);
        }
    }
}

module.exports = ReportGenerator;
