FROM node:20-bullseye

RUN apt-get update && apt-get install -y \
    docker.io \
    docker-compose \
    git \
    bash \
    curl \
    openssl \
    openssh-client \
    python3 \
    make \
    g++ \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /root/.ssh && \
    ssh-keyscan github.com >> /root/.ssh/known_hosts && \
    ssh-keyscan github.com >> /etc/ssh/ssh_known_hosts

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

EXPOSE 8080

CMD ["npm", "start"]
