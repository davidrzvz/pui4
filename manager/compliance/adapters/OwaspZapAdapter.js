const BaseAdapter = require('./BaseAdapter');
const util = require('util');
const exec = util.promisify(require('child_process').exec);
const path = require('path');

class OwaspZapAdapter extends BaseAdapter {
    constructor() {
        super('zap-cli');
    }

    async run(options) {
        const { targetUrl, outputDir } = options;
        const outputPath = path.join(outputDir, 'zap-report.json');
        
        try {
            // A simplified zap-cli flow
            console.log(`Starting ZAP scan on ${targetUrl}`);
            await exec(`zap-cli start`);
            await exec(`zap-cli spider ${targetUrl}`);
            await exec(`zap-cli active-scan ${targetUrl}`);
            await exec(`zap-cli report -o ${outputPath} -f json`);
            await exec(`zap-cli status`);
            return outputPath;
        } catch (error) {
            console.error(`zap-cli execution error: ${error.message}`);
            return outputPath;
        } finally {
            // Always try to stop ZAP
            try {
                await exec(`zap-cli shutdown`);
            } catch (ignore) {}
        }
    }

    parseResults(rawOutput) {
        return {
            findings: [],
            vulnerabilities_count: 0
        };
    }
}

module.exports = OwaspZapAdapter;
