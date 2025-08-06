##### Dockerfile
```Dockerfile
FROM rockylinux:8

# タイムゾーン設定（任意）
RUN ln -sf /usr/share/zoneinfo/Asia/Tokyo /etc/localtime && \
    dnf install -y tzdata && \
    dnf clean all

# 基本ツール + passwdコマンド含むshadow-utilsをインストール
RUN dnf update -y && \
    dnf install -y vim curl wget shadow-utils passwd && \
    dnf clean all

WORKDIR /app
CMD ["/bin/bash"]
```

##### bash
```bash
docker build -t my-rocky8 .
docker run -it --rm my-rocky8
```