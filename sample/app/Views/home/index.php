<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ci4-request-analysis demo</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        fieldset { margin-bottom: 1.5rem; }
        label { display: block; margin-top: .5rem; }
        input { width: 100%; padding: .35rem; box-sizing: border-box; }
        button { margin-top: 1rem; padding: .5rem 1rem; }
        pre { background: #f4f4f4; padding: 1rem; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>ci4-request-analysis demo</h1>

    <fieldset>
        <legend>POST (JSON)</legend>
        <form id="json-form">
            <label>name <input name="name" value="User"></label>
            <label>email <input name="email" value="user@example.com"></label>
            <label>password <input name="password" value="secret123"></label>
            <button type="submit">Send JSON POST</button>
        </form>
    </fieldset>

    <fieldset>
        <legend>POST (multipart / file upload)</legend>
        <form id="upload-form" enctype="multipart/form-data" action="upload" method="post">
            <label>title <input name="title" value="My avatar"></label>
            <label>file <input type="file" name="avatar"></label>
            <button type="submit">Upload file</button>
        </form>
    </fieldset>

    <pre id="output">—</pre>

    <script>
        document.getElementById('json-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(e.target));
            const res = await fetch('post', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            document.getElementById('output').textContent = JSON.stringify(await res.json(), null, 2);
        });
    </script>
</body>
</html>
