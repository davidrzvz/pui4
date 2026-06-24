FROM node:20-alpine

# Install Docker CLI and other required tools (git, bash, openssl)
RUN apk add --no-cache docker-cli git bash openssl openssh-client

RUN mkdir -p /root/.ssh && \
    ssh-keyscan github.com >> /root/.ssh/known_hosts && \
    ssh-keyscan github.com >> /etc/ssh/ssh_known_hosts

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

EXPOSE 8080

CMD ["npm", "start"]
