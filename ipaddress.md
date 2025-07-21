### 🔧 固定IPアドレスとDNSを設定する手順（Amazon Linux 2023）
 - Amazon Linux 2023 は、systemd-networkd を使ってネットワークを管理しています。
  （NetworkManager や netplan ではありません）
 - IPアドレスとDNSを固定で設定するには、以下のような手順になります。

---

#### ① 設定ファイルの作成または編集
`/etc/systemd/network/` 以下に `.network` ファイルを作成または編集します。

例：`/etc/systemd/network/10-ens192.network`

```ini
[Match]
Name=ens192

[Network]
Address=192.168.0.200/24
Gateway=192.168.0.1
DNS=202.122.48.103
DNS=8.8.8.8
```
🔸 `Name=` には対象のNIC名を記入（例：`ens192` や `eth0`）

---

#### ② 現在のインターフェース名の確認
```bash
ip a
```
NICの名前を確認し、設定ファイルの `[Match] Name=` に正しく記載してください。

---

#### ③ DHCPを無効にする（必要に応じて）
DHCPが有効だと静的IPが上書きされる可能性があるので、以下を確認します：

```bash
sudo systemctl disable systemd-networkd-wait-online
sudo systemctl stop systemd-networkd-wait-online
```
また、`/etc/systemd/network/` に DHCP 設定の `.network` ファイルがあれば削除または DHCP=no にします。

---

#### ④ 設定を有効化して再起動
```bash
sudo systemctl restart systemd-networkd
```

---

#### ⑤ 設定確認
```bash
ip a        # IPアドレス確認

systemd-resolve --status  # DNS設定確認
```

---

#### 📎 補足
- 永続化されるので再起動しても設定は保持されます。
- `resolv.conf` は `systemd-resolved` により自動生成されます。直接編集しないでください。

---
