class BaseAdapter {
    constructor(toolName) {
        this.toolName = toolName;
    }

    /**
     * Run the underlying tool.
     * @param {Object} options Configuration and target info
     */
    async run(options) {
        throw new Error("Method not implemented.");
    }

    /**
     * Parse the raw output of the tool into a normalized finding format.
     * @param {String|Object} rawOutput 
     */
    parseResults(rawOutput) {
        throw new Error("Method not implemented.");
    }
}

module.exports = BaseAdapter;
