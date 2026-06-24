FROM node:20-alpine

# Install Docker CLI and other required tools (git, bash, openssl)
RUN apk add --no-cache docker-cli git bash openssl

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

EXPOSE 8080

CMD ["npm", "start"]
