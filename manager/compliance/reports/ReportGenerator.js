const fs = require('fs');
const path = require('path');
const ejs = require('ejs');
const { chromium } = require('playwright');
const archiver = require('archiver');

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
        
        // Ensure templates exist (we will dynamically create simple ones if missing for this demo)
        const execTemplatePath = path.join(this.templatesDir, 'executive.ejs');
        const techTemplatePath = path.join(this.templatesDir, 'technical.ejs');
        
        this._ensureBasicTemplate(execTemplatePath, 'Executive Report');
        this._ensureBasicTemplate(techTemplatePath, 'Technical Report');

        // Render HTML
        const execHtml = await ejs.renderFile(execTemplatePath, { audit: auditData });
        const techHtml = await ejs.renderFile(techTemplatePath, { audit: auditData });

        // Generate PDFs
        const execPdfPath = path.join(auditDir, 'Executive_Report.pdf');
        const techPdfPath = path.join(auditDir, 'Technical_Report.pdf');
        
        await this._htmlToPdf(execHtml, execPdfPath);
        await this._htmlToPdf(techHtml, techPdfPath);

        // Generate Manifest
        const manifest = {
            version: '1.0',
            audit_id: auditId,
            generated_at: new Date().toISOString(),
            files: [
                'Executive_Report.pdf',
                'Technical_Report.pdf',
                'metadata.json'
            ]
        };
        fs.writeFileSync(path.join(auditDir, 'manifest.json'), JSON.stringify(manifest, null, 2));

        // Create final ZIP
        const zipPath = path.join(auditDir, 'security-report.zip');
        await this._createZip(auditDir, zipPath);

        return zipPath;
    }

    async _htmlToPdf(htmlContent, outputPath) {
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
                    <p>Audit ID: <%= audit.id %></p>
                    <p>Date: <%= audit.date %></p>
                    <h2>Findings Summary</h2>
                    <p>Vulnerabilities: <%= audit.vulnerabilities_count %></p>
                </body>
                </html>
            `);
        }
    }
}

module.exports = ReportGenerator;
