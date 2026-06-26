const BaseAdapter = require('./BaseAdapter');
const util = require('util');
const exec = util.promisify(require('child_process').exec);
const path = require('path');
const fs = require('fs');

class ComposerAuditAdapter extends BaseAdapter {
    constructor() {
        super('composer');
    }

    async run(options) {
        const { targetPath, outputDir } = options;
        const outputPath = path.join(outputDir, 'composer-audit-results.json');
        
        try {
            // Run composer audit and output JSON
            // We ensure we run this in the target path
            const cmd = `composer audit --format=json > ${outputPath}`;
            await exec(cmd, { cwd: targetPath });
            return outputPath;
        } catch (error) {
            console.error(`Composer audit execution warning/error: ${error.message}`);
            // Sometimes it errors out if findings exist, but still creates the JSON (if > is used carefully, though > might not capture stderr).
            // Better to capture stdout directly if needed.
            if (error.stdout && error.stdout.trim() !== '') {
                fs.writeFileSync(outputPath, error.stdout);
            }
            return outputPath;
        }
    }

    parseResults(rawOutput) {
        return {
            findings: [],
            vulnerabilities_count: 0
        };
    }
}

module.exports = ComposerAuditAdapter;
