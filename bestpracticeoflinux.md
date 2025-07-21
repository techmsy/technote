#### ✅ Linuxのインストール・初期設定・設定変更作業におけるベストプラクティス

---

##### ✅ 1. rootユーザー直接使用は避ける
✖ suでrootになりっぱなしは危険
- suはパスワードを知っていれば誰でもrootになれる
- 作業ミスによる被害が致命的（誤ってrm -rf /など）

✔ sudoで必要なときだけ昇格
- コマンドごとにroot権限を借りる方式
- ログに残るので監査に有効（/var/log/auth.log 等）
- パスワード入力で確認できる（セキュリティ強化）

---

##### ✅ 2. 一般ユーザーを作成し、sudo権限を付与する
```bash
# 一般ユーザー作成
useradd -m -s /bin/bash tuser
passwd tuser
```
```bash
# sudoグループに追加（ディストリビューションによって異なる）
usermod -aG wheel tuser   # CentOS/RHEL/Rocky系
usermod -aG sudo tuser    # Debian/Ubuntu系
```
sudo確認
```bash
sudo whoami
```
出力: root ならOK

---

##### ✅ 3. sudoのベストプラクティス
| 作業内容 | 推奨方法 |
|-------|-------|
| 一時的にrootとして複数作業 | sudo -i または sudo -s |
| 1コマンドのみroot実行 | sudo command |
| rootのまま作業継続 |	非推奨。間違い防止のため必要最低限に |
| sudo su - |	使用可能だが監査ログが残りにくい |

---

##### ✅ 4. sudoersファイルの管理（必要なら限定許可）
> visudo で編集（誤編集防止のため）
```bash
sudo visudo
```
例：tuserが特定コマンドだけ実行可に
tuser ALL=(ALL) NOPASSWD: /usr/sbin/systemctl restart nginx

---

##### ✅ 5. 初期設定でやっておくこと一覧
| 項目 | ベストプラクティス例 |
|-------|-------|
| ユーザー作成|	上述のとおり|
| SSH設定 | rootログイン禁止 PermitRootLogin no |
| ファイアウォール設定 | firewalldまたはufwで必要ポートのみ許可 |
| パッケージアップデート | dnf update や apt update && upgrade |
| タイムゾーン設定 | timedatectl set-timezone Asia/Tokyo |
| NTP/chrony設定 | 時刻同期の有効化 |
| SELinux/AppArmor | 適切に設定・必要に応じてPermissiveに |
| ログローテーション | logrotate 確認・カスタマイズ |
| 不要サービスの停止 | systemctl disable <service> |
| 自動アップデート | 検討：unattended-upgradesなど |

---

##### ✅ 6. rootアカウントを無効化する必要は？
- No：rootアカウント自体はシステム上必要（リカバリ時など）
- Yes（制限は推奨）：直接ログインさせず、sudo経由でのみ操作

---

##### ✅ 結論：ベストプラクティスまとめ
| 項目 | 推奨設定 |
|-------|-------|
| rootで直接作業 | ❌避ける |
| 一般ユーザーでログイン | ✅ sudoで必要時のみroot権限付与 |
| su使用 | ❌非推奨（ログが残らない、事故リスク大） |
| sudo使用 | ✅安全、ログ、限定許可が可能 |
| rootログイン（SSH） | ❌禁止（/etc/ssh/sshd_config） |
| sudo対象ユーザー管理 | ✅ visudoで適切に制限 |

---
