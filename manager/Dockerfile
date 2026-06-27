FROM node:20-alpine

RUN apk add --no-cache \
    docker-cli \
    docker-cli-compose \
    git \
    bash \
    curl \
    openssl \
    openssh-client \
    python3 \
    make \
    g++ \
    gcc

RUN mkdir -p /root/.ssh && \
    ssh-keyscan github.com >> /root/.ssh/known_hosts && \
    ssh-keyscan github.com >> /etc/ssh/ssh_known_hosts

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

EXPOSE 8080

CMD ["npm", "start"]