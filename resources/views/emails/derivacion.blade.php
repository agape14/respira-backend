<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Derivación a Especialista - Respira</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }
        .header {
            background-color: #6A1B9A;
            background: linear-gradient(135deg, #6A1B9A 0%, #4a148c 100%);
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #ffffff;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            color: #2c3e50;
            margin-bottom: 20px;
        }
        .intro-text {
            font-size: 16px;
            line-height: 1.6;
            color: #555;
            margin-bottom: 30px;
        }
        .details-box {
            background-color: #f8f9fa;
            border-left: 5px solid #4CAF50;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .details-item {
            margin-bottom: 12px;
            font-size: 15px;
            display: flex;
            align-items: center;
        }
        .details-item:last-child {
            margin-bottom: 0;
        }
        .details-label {
            font-weight: 700;
            color: #4a148c;
            width: 120px;
            flex-shrink: 0;
        }
        .details-value {
            color: #333;
        }
        .footer {
            background-color: #f1f1f1;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e0e0e0;
        }
        .footer-logo {
            margin-bottom: 15px;
            text-align: center;
        }
        .footer-logo img {
            width: 100px;
            height: auto;
            display: inline-block;
        }
        .footer-text {
            font-size: 13px;
            color: #7f8c8d;
            margin: 5px 0;
        }
        .copyright {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header" style="background-color: #6A1B9A; color: #ffffff;">
            <h1 style="color: #ffffff;">Derivación a Especialista</h1>
        </div>
        <div class="content">
            <p class="greeting">Hola <strong>{{ $nombrePaciente }}</strong>,</p>
            <p class="intro-text">Le informamos que, como parte de su proceso de atención, ha sido derivado a un especialista para continuar con su seguimiento.</p>
            
            <div class="details-box">
                <div class="details-item">
                    <span class="details-label">Especialista:</span>
                    <span class="details-value">{{ $nombreEspecialista }}</span>
                </div>
                <div class="details-item">
                    <span class="details-label">Fecha:</span>
                    <span class="details-value">{{ $fecha }}</span>
                </div>
                @if(!empty($observacion))
                <div class="details-item">
                    <span class="details-label">Observación:</span>
                    <span class="details-value">{{ $observacion }}</span>
                </div>
                @endif
            </div>

            <p class="intro-text">El especialista o el equipo administrativo se pondrá en contacto con usted para coordinar su próxima atención.</p>
        </div>
        <div class="footer">
            <div class="footer-logo">
                <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="Logo CMP" width="100" style="width: 100px; height: auto;">
            </div>
            <p class="footer-text">Colegio Médico del Perú - Programa Respira</p>
            <p class="footer-text">Cuidando de quienes nos cuidan.</p>
            <p class="copyright">&copy; {{ date('Y') }} Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>
