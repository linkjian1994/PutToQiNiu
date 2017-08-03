# PutToQiNiu
Typecho Plugin：将文件上传至七牛云存储

### 说明
  * SKD版本为最新(7.x.x)，适用于 `PHP>=5.3.0`
  * 支持文件上传，更改，删除
  * 启用插件后文件将存储至七牛，服务器不在存储文件
  
### 安装
   * 下载本插件，存放至 `usr/plugins/` 目录中
   ```
   cd $base_dir/usr/plugins
   git clone https://github.com/seasLaugh/PutToQiNiu.git
   ```
### 如何使用
   * 在插件管理中启用PutToQiNiu
   * 点击设置进行七牛空间信息配置
   * 填写AccessKey,SecretKey,Bucket,绑定域名
   * 保存设置即可