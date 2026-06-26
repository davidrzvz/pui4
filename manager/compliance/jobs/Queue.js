class Queue {
    /**
     * Push a job to the queue.
     * @param {Object} jobData 
     * @returns {Promise<String|Number>} jobId
     */
    async push(jobData) {
        throw new Error("Method not implemented.");
    }

    /**
     * Retrieve the next pending job.
     * @returns {Promise<Object>} job
     */
    async pop() {
        throw new Error("Method not implemented.");
    }

    /**
     * Update the status of a job.
     * @param {String|Number} jobId 
     * @param {String} status 
     * @param {Number} progress 
     */
    async updateStatus(jobId, status, progress) {
        throw new Error("Method not implemented.");
    }
}

module.exports = Queue;
