```shell
mv ./migrations/{파일명} /path/to/todo/migrations
```

```shell
docker compose restart todo-pocketbase
```

```shell
docker compose exec todo-pocketbase pocketbase migrate down
docker compose exec todo-pocketbase pocketbase migrate up
```
