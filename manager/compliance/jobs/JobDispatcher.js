class JobDispatcher {
    constructor(queue) {
        this.queue = queue;
    }

    /**
     * Dispatch a new audit job.
     * @param {Object} auditInfo 
     * @returns {Promise<String|Number>} jobId
     */
    async dispatch(auditInfo) {
        // auditInfo must contain { auditId: ... }
        return await this.queue.push(auditInfo);
    }
}

module.exports = JobDispatcher;
