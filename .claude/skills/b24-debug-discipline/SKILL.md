---
name: b24-debug-discipline
description: Use when encountering any bug or unexpected behavior in this Bitrix24 app, before proposing a fix — find the root cause instead of guessing.
---

1. Воспроизведи баг — конкретный шаг/запрос, который его вызывает. Если не можешь
   воспроизвести — это первая задача, не пропускай её.
2. Прежде чем чинить — проверь `/projects/docs/rules-b24/INDEX.md` (`grep`): это уже известная грабля Б24?
3. Найди корневую причину (лог, реальный HTTP-ответ, реальное состояние токенов/сессии) —
   не чини по предположению.
4. Если причина новая и не описана в правилах — после фикса добавь новый файл в `/projects/docs/rules-b24/` и
   запись в `/projects/docs/rules-b24/INDEX.md`, чтобы не искать повторно.
5. Не считай баг закрытым, пока не прогнал тот же сценарий, что его вызвал, и не увидел,
   что он больше не воспроизводится.
