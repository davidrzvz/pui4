const BaseAdapter = require('./BaseAdapter');
const util = require('util');
const exec = util.promisify(require('child_process').exec);
const path = require('path');

class SemgrepAdapter extends BaseAdapter {
    constructor() {
        super('semgrep');
    }

    async run(options) {
        const { targetPath, outputDir } = options;
        const outputPath = path.join(outputDir, 'semgrep-results.json');
        
        try {
            // Run semgrep and output to JSON
            const cmd = `semgrep scan --config=auto --json -o ${outputPath} ${targetPath}`;
            await exec(cmd);
            return outputPath;
        } catch (error) {
            // semgrep often exits with code > 0 if it finds issues, so we need to handle that gracefully
            // Or if it failed to run entirely.
            console.error(`Semgrep execution warning/error: ${error.message}`);
            return outputPath; // Output path might still contain findings
        }
    }

    parseResults(rawOutput) {
        // Here we would read the JSON and normalize to our standard format
        return {
            findings: [],
            vulnerabilities_count: 0
        };
    }
}

module.exports = SemgrepAdapter;
