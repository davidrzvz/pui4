const BaseAdapter = require('./BaseAdapter');
const util = require('util');
const exec = util.promisify(require('child_process').exec);
const path = require('path');
const fs = require('fs');

class NpmAuditAdapter extends BaseAdapter {
    constructor() {
        super('npm');
    }

    async run(options) {
        const { targetPath, outputDir } = options;
        const outputPath = path.join(outputDir, 'npm-audit-results.json');
        
        try {
            // Ensure running inside targetPath
            const cmd = `npm audit --json > ${outputPath}`;
            await exec(cmd, { cwd: targetPath });
            return outputPath;
        } catch (error) {
            console.error(`npm audit execution warning/error: ${error.message}`);
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

module.exports = NpmAuditAdapter;
