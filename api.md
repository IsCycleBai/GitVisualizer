# GitVisualizer API

GitVisualizer 是一个用于可视化 Git 仓库提交历史的 PHP 应用。它通过 GitHub 或 GitLab 的公开 API 获取提交信息，并生成 SVG 图像以直观地展示提交历史。

## 请求参数

GitVisualizer API 接受以下 GET 请求参数：

| 参数名      | 类型     | 是否必需 | 默认值 | 描述                                             |
|------------|----------|----------|--------|--------------------------------------------------|
| repo       | string   | 是       | 无     | GitHub 或 GitLab 仓库的 URL                       |
| limit      | integer  | 否       | 10     | 要获取的最大提交数量，范围为 1-50                  |
| dark_mode  | boolean  | 否       | false  | 是否使用深色主题，true 表示使用深色主题             |
| branch     | string   | 否       | main   | 要获取提交的分支名称                              |

## 响应

API 将返回生成的 SVG 图像，`Content-Type` 为 `image/svg+xml`。如果请求参数无效或 API 出现错误，将返回相应的 HTTP 状态码和 JSON 格式的错误信息。

## 示例请求

```
https://your-domain.com/path/to/gitviz.php?repo=https://github.com/username/repo&limit=15&dark_mode=true&branch=develop
```

## 错误处理

如果请求参数无效或 API 出现错误，将返回以下 HTTP 状态码和 JSON 格式的错误信息：

- 400 Bad Request：请求参数无效
- 500 Internal Server Error：服务器内部错误

错误响应格式：

```json
{
  "success": false,
  "errors": [
    "错误消息 1",
    "错误消息 2"
  ]
}
```
