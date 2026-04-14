<?php
// Archivo de Extensión: Metodología Tres Puntos
// Este archivo se inyecta automáticamente al final de todas las propuestas funcionales.
?>
<section id="metodologia-trespuntos" class="tp-metodologia-section">
    <h2>Proceso de trabajo – Metodología Tres Puntos</h2>
    <p class="metodologia-intro">
        El proyecto se desarrollará siguiendo la metodología propia de Tres Puntos, estructurada en cuatro fases
        claramente definidas y aplicable de forma genérica a los proyectos digitales de la agencia.
    </p>

    <div class="tp-timeline-container">
        <!-- Línea conectora de fondo -->
        <div class="tp-timeline-line"></div>
        <div class="tp-timeline-progress"></div>

        <!-- Fase 1 -->
        <div class="tp-timeline-step">
            <div class="tp-step-indicator">
                <div class="tp-step-glow"></div>
                <div class="tp-step-number">01</div>
                <div class="tp-step-icon"><i data-lucide="search"></i></div>
            </div>
            <div class="tp-step-card">
                <h3>Análisis</h3>
                <ul class="tp-step-list">
                    <li><i data-lucide="check"></i>Reuniones iniciales para comprender la empresa, contexto y objetivos.
                    </li>
                    <li><i data-lucide="check"></i>Revisión estratégica del briefing y de los activos existentes.</li>
                    <li><i data-lucide="check"></i>Validación de la arquitectura de información y sitemap.</li>
                    <li><i data-lucide="check"></i>Definición de requisitos funcionales y técnicos.</li>
                    <li><i data-lucide="check"></i>Recopilación de accesos necesarios y coordinación con partners.</li>
                </ul>
                <div class="tp-step-badge"><i data-lucide="target"></i> Reducir incertidumbre y alinear expectativas
                </div>
            </div>
        </div>

        <!-- Fase 2 -->
        <div class="tp-timeline-step">
            <div class="tp-step-indicator">
                <div class="tp-step-glow"></div>
                <div class="tp-step-number">02</div>
                <div class="tp-step-icon"><i data-lucide="layout-template"></i></div>
            </div>
            <div class="tp-step-card">
                <h3>Diseño UX/UI</h3>
                <p class="tp-step-desc">Trabajo continuo y colaborativo con validación en cada iteración.</p>
                <ul class="tp-step-list">
                    <li><i data-lucide="check"></i>Diseño inicial de la versión Mobile como base del proyecto.</li>
                    <li><i data-lucide="check"></i>Revisiones y validación completa de la versión móvil.</li>
                    <li><i data-lucide="check"></i>Replicación del proceso para la versión Desktop.</li>
                    <li><i data-lucide="check"></i>Definición del sistema de bloques reutilizables y componentes clave.
                    </li>
                </ul>
                <div class="tp-step-badge warning"><i data-lucide="alert-triangle"></i> Tras aprobación, cambios de
                    diseño requieren presupuesto adicional</div>
            </div>
        </div>

        <!-- Fase 3 -->
        <div class="tp-timeline-step">
            <div class="tp-step-indicator">
                <div class="tp-step-glow"></div>
                <div class="tp-step-number">03</div>
                <div class="tp-step-icon"><i data-lucide="code-xml"></i></div>
            </div>
            <div class="tp-step-card">
                <h3>Desarrollo Técnico</h3>
                <p class="tp-step-desc">Construcción sobre entorno de pruebas (staging) fiel a UX/UI.</p>
                <ul class="tp-step-list">
                    <li><i data-lucide="check"></i>Maquetación fiel respetando estructura, jerarquías y componentes.
                    </li>
                    <li><i data-lucide="check"></i>Implantación de microinteracciones, animaciones y transiciones.</li>
                    <li><i data-lucide="check"></i>Optimización de rendimiento (carga diferida, formatos e imágenes).
                    </li>
                    <li><i data-lucide="check"></i>Integración de formularios y herramientas de terceros.</li>
                </ul>
                <div class="tp-step-badge info"><i data-lucide="monitor-play"></i> Basado exclusivamente en el diseño
                    previamente aprobado</div>
            </div>
        </div>

        <!-- Fase 4 -->
        <div class="tp-timeline-step">
            <div class="tp-step-indicator">
                <div class="tp-step-glow"></div>
                <div class="tp-step-number">04</div>
                <div class="tp-step-icon"><i data-lucide="rocket"></i></div>
            </div>
            <div class="tp-step-card">
                <h3>Migración y Lanzamiento</h3>
                <ul class="tp-step-list">
                    <li><i data-lucide="check"></i>Migración de contenidos validados al nuevo entorno.</li>
                    <li><i data-lucide="check"></i>Implementación de redirecciones 301 para preservar el SEO.</li>
                    <li><i data-lucide="check"></i>Revisión final técnica y de rendimiento antes de publicar.</li>
                    <li><i data-lucide="check"></i>Testeo QA en distintos dispositivos y navegadores.</li>
                    <li><i data-lucide="check"></i>Publicación final tras validación formal.</li>
                </ul>
                <div class="tp-step-badge success"><i data-lucide="sparkles"></i> Lanzamiento oficial del proyecto</div>
            </div>
        </div>
    </div>

    <div class="tp-metodologia-footer">
        <i data-lucide="shield-check"></i>
        <p>Cada fase incluirá hitos de validación por parte del cliente antes de avanzar a la siguiente, garantizando el
            control sobre el resultado final.</p>
    </div>
</section>

<style>
    /* Estilos Ultra-Premium para la Metodología */
    .tp-metodologia-section {
        margin: 4rem 0 6rem 0;
        padding: 3rem;
        background: rgba(255, 255, 255, 0.015);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 24px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        position: relative;
        overflow: hidden;
    }

    .tp-metodologia-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(93, 255, 191, 0.3), transparent);
    }

    .metodologia-intro {
        font-size: 1.15rem;
        line-height: 1.6;
        color: #BDBDBD;
        margin-bottom: 4rem;
        max-width: 800px;
    }

    /* Container de la linea de tiempo */
    .tp-timeline-container {
        display: flex;
        flex-direction: column;
        gap: 3rem;
        position: relative;
        padding-left: 2rem;
    }

    .tp-timeline-line {
        position: absolute;
        left: 48px;
        /* alineado con el centro del icono */
        top: 24px;
        bottom: 24px;
        width: 2px;
        background: rgba(255, 255, 255, 0.1);
        z-index: 1;
    }

    .tp-timeline-progress {
        position: absolute;
        left: 48px;
        top: 24px;
        height: 0%;
        /* Se podría animar con JS al hacer scroll */
        width: 2px;
        background: var(--tp-primary, #5DFFBF);
        box-shadow: 0 0 10px var(--tp-primary, #5DFFBF);
        z-index: 2;
        transition: height 0.5s ease;
    }

    /* Pasos */
    .tp-timeline-step {
        display: flex;
        align-items: flex-start;
        gap: 2.5rem;
        position: relative;
        z-index: 3;
    }

    .tp-timeline-step:hover .tp-step-glow {
        opacity: 1;
        transform: scale(1.2);
    }

    .tp-timeline-step:hover .tp-step-icon {
        background: var(--tp-primary, #5DFFBF);
        color: #0E0E0E;
        transform: scale(1.1);
        box-shadow: 0 0 20px rgba(93, 255, 191, 0.4);
    }

    .tp-timeline-step:hover .tp-step-card {
        border-color: rgba(93, 255, 191, 0.3);
        background: rgba(255, 255, 255, 0.04);
        transform: translateX(10px);
    }

    /* Indicador (Icono + Numero) */
    .tp-step-indicator {
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
        width: 60px;
        flex-shrink: 0;
    }

    .tp-step-glow {
        position: absolute;
        width: 60px;
        height: 60px;
        background: radial-gradient(circle, rgba(93, 255, 191, 0.2) 0%, transparent 70%);
        opacity: 0;
        transition: all 0.4s ease;
        z-index: -1;
    }

    .tp-step-icon {
        width: 56px;
        height: 56px;
        border-radius: 16px;
        background: #1A1A1A;
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #E0E0E0;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        z-index: 2;
    }

    .tp-step-icon svg {
        width: 24px;
        height: 24px;
    }

    .tp-step-number {
        margin-top: 1rem;
        font-family: var(--font-heading, 'Plus Jakarta Sans');
        font-size: 1.5rem;
        font-weight: 800;
        color: rgba(255, 255, 255, 0.1);
    }

    /* Tarjeta del paso */
    .tp-step-card {
        flex: 1;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 16px;
        padding: 2rem;
        transition: all 0.4s ease;
    }

    .tp-step-card h3 {
        margin: 0 0 0.5rem 0;
        font-size: 1.5rem;
        color: #FFF;
        font-family: var(--font-heading, 'Plus Jakarta Sans');
    }

    .tp-step-desc {
        color: #A0A0A0;
        font-size: 0.95rem;
        margin-bottom: 1.2rem;
    }

    .tp-step-list {
        list-style: none;
        padding: 0;
        margin: 0 0 1.5rem 0;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .tp-step-list li {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        color: #D0D0D0;
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .tp-step-list li i {
        color: var(--tp-primary, #5DFFBF);
        width: 18px;
        height: 18px;
        flex-shrink: 0;
        margin-top: 2px;
    }

    /* Badges */
    .tp-step-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        font-size: 0.85rem;
        color: #FFF;
        font-weight: 500;
    }

    .tp-step-badge svg {
        width: 14px;
        height: 14px;
    }

    .tp-step-badge.warning {
        background: rgba(245, 158, 11, 0.1);
        color: #FCD34D;
    }

    .tp-step-badge.info {
        background: rgba(59, 130, 246, 0.1);
        color: #93C5FD;
    }

    .tp-step-badge.success {
        background: rgba(93, 255, 191, 0.1);
        color: #5DFFBF;
    }


    /* Footer Metodología */
    .tp-metodologia-footer {
        margin-top: 4rem;
        padding: 1.5rem;
        background: rgba(93, 255, 191, 0.05);
        border: 1px dashed rgba(93, 255, 191, 0.2);
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 1.5rem;
    }

    .tp-metodologia-footer i {
        width: 32px;
        height: 32px;
        color: var(--tp-primary, #5DFFBF);
        flex-shrink: 0;
    }

    .tp-metodologia-footer p {
        margin: 0;
        color: #E0E0E0;
        font-size: 0.95rem;
        font-weight: 500;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .tp-metodologia-section {
            padding: 1.5rem;
        }

        .tp-timeline-container {
            padding-left: 0;
            gap: 2rem;
        }

        .tp-timeline-line,
        .tp-timeline-progress {
            display: none;
            /* Quitamos la línea en móvil para simplificar */
        }

        .tp-timeline-step {
            flex-direction: column;
            gap: 1rem;
        }

        .tp-step-indicator {
            flex-direction: row;
            width: 100%;
            justify-content: flex-start;
            gap: 1rem;
        }

        .tp-step-number {
            margin-top: 0;
        }

        .tp-step-card {
            padding: 1.5rem;
        }

        .tp-metodologia-footer {
            flex-direction: column;
            text-align: center;
            gap: 1rem;
        }
    }

    /* -------------------------------------------------- */
    /* LIGHT MODE — overrides de contraste                 */
    /* Los estilos originales son todos rgba(255,255,255,…) */
    /* que sobre fondo claro quedan invisibles.            */
    /* -------------------------------------------------- */
    [data-theme="light"] .tp-metodologia-section {
        background: var(--bg-surface, #fff);
        border-color: var(--border-base, #e5e5e5);
        box-shadow: 0 1px 2px rgba(20, 20, 20, .04);
    }
    [data-theme="light"] .metodologia-intro { color: var(--text-secondary, #4a4a4a); }
    [data-theme="light"] .tp-timeline-line { background: var(--border-base, #e5e5e5); }
    [data-theme="light"] .tp-step-icon {
        background: var(--bg-base, #fafafa);
        border-color: var(--border-base, #e5e5e5);
        color: var(--text-primary, #0e0e0e);
    }
    [data-theme="light"] .tp-step-number { color: rgba(20, 20, 20, .25); }
    [data-theme="light"] .tp-step-card {
        background: var(--bg-base, #fafafa);
        border-color: var(--border-base, #e5e5e5);
    }
    [data-theme="light"] .tp-step-card h3 { color: var(--text-primary, #0e0e0e); }
    [data-theme="light"] .tp-step-desc { color: var(--text-secondary, #4a4a4a); }
    [data-theme="light"] .tp-step-list li { color: var(--text-primary, #0e0e0e); }
    [data-theme="light"] .tp-step-badge {
        background: var(--bg-subtle, #f0f0f0);
        color: var(--text-primary, #0e0e0e);
    }
    [data-theme="light"] .tp-step-badge.warning { background: rgba(245, 158, 11, .12); color: #92580c; }
    [data-theme="light"] .tp-step-badge.info    { background: rgba(59, 130, 246, .12); color: #1d4ed8; }
    [data-theme="light"] .tp-step-badge.success { background: rgba(16, 163, 127, .12); color: #0e7a5f; }
    [data-theme="light"] .tp-metodologia-footer {
        background: rgba(16, 163, 127, .06);
        border-color: rgba(16, 163, 127, .25);
    }
    [data-theme="light"] .tp-metodologia-footer p { color: var(--text-primary, #0e0e0e); }
    [data-theme="light"] .tp-metodologia-footer i { color: #0e7a5f; }
</style>