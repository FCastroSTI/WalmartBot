<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulario Ticket</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

    <div class="container mt-4">
        <h3 class="text-center mb-4">Ingreso de Ticket</h3>

        @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <form action="/formulario-ticket" method="POST" class="card p-4">
            @csrf

            <input type="hidden" name="phone" value="{{ $phone }}">

            <div class="mb-3">
                <label>Número de local</label>
                <input type="text" name="local" class="form-control" required>
            </div>

            <div class="row">
                <div class="col">
                    <label>Nivel 1</label>
                    <input type="text" name="nivel1" class="form-control" required>
                </div>
                <div class="col">
                    <label>Nivel 2</label>
                    <input type="text" name="nivel2" class="form-control" required>
                </div>
                <div class="col">
                    <label>Nivel 4</label>
                    <input type="text" name="nivel4" class="form-control" required>
                </div>
            </div>

            <hr>

            <div class="row mt-3">
                <div class="col">
                    <label>Marca</label>
                    <input type="text" name="marca" class="form-control" required>
                </div>
                <div class="col">
                    <label>Modelo</label>
                    <input type="text" name="modelo" class="form-control" required>
                </div>
                <div class="col">
                    <label>Serie</label>
                    <input type="text" name="serie" class="form-control" required>
                </div>
            </div>

            <hr>

            <div class="row mt-3">
                <div class="col">
                    <label>Nombre solicitante</label>
                    <input type="text" name="nombre" class="form-control" required>
                </div>
                <div class="col">
                    <label>Cargo solicitante</label>
                    <input type="text" name="cargo" class="form-control" required>
                </div>
            </div>

            <div class="mt-3">
                <label>Email solicitante</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="mt-3">
                <label>Observación / Descripción</label>
                <textarea name="observacion" rows="3" class="form-control" required></textarea>
            </div>

            <button class="btn btn-primary mt-3 w-100">Enviar Ticket</button>

        </form>

    </div>

</body>

</html>