# GitVisualizer

GitVisualizer 是一个用于可视化 Git 仓库提交历史的 PHP 应用。它通过 GitHub 或 GitLab 的公开 API 获取提交信息，并生成 SVG 图像以直观地展示提交历史。

## 功能特点

- 支持 GitHub 和 GitLab 仓库
- 使用 Conventional Commits 规范解析提交信息
- 支持限制获取的提交数量
- 支持深色和浅色主题
- 支持指定获取提交的分支

## 安装部署

1. 将 `gitviz.php` 文件上传到你的 PHP 服务器
2. 确保 PHP 版本不低于 7.0
3. 确保 `logs` 目录可写，或修改 `GITVIZ_LOG_PATH` 常量指定其他可写目录
4. 访问 `https://your-domain.com/path/to/gitviz.php` 并传入必要的请求参数

## 使用方法

通过向 `gitviz.php` 发送 GET 请求并传入必要的参数来获取 SVG 图像，详见 [API 文档](./API.md)。

示例请求：

```
https://your-domain.com/path/to/gitviz.php?repo=https://github.com/username/repo&limit=15&dark_mode=true&branch=develop
```

## 配置

可以通过修改 `gitviz.php` 文件中的常量来配置应用：

- `GITVIZ_VERSION`：应用版本号
- `GITVIZ_LOG_PATH`：日志文件目录路径
- `GITVIZ_LOG_FILE`：日志文件名，默认为 `gitviz-YYYY-MM-DD.log`
- `GITVIZ_DEBUG`：是否开启调试模式，设为 `true` 时将错误信息输出到 PHP 错误日志

## 贡献

欢迎提交 Issue 和 Pull Request 来改进 GitVisualizer。在提交 Pull Request 之前，请先阅读[贡献指南](./CONTRIBUTING.md)。

## 许可

GitVisualizer 采用 [MIT 许可证](./LICENSE)开源。
