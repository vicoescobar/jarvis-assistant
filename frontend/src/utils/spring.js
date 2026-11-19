/**
 * Apple WWDC18 "Designing Fluid Interfaces" physical motion primitives:
 * - Momentum projection
 * - Rubber-banding at soft boundaries
 * - Interruptible requestAnimationFrame spring parameterized by (dampingRatio, response)
 */

export function prefersReducedMotion() {
  return (
    typeof window !== 'undefined' &&
    window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
  )
}

/**
 * Project resting position from release velocity (px/s).
 */
export function project(initialVelocity, decelerationRate = 0.998) {
  return ((initialVelocity / 1000) * decelerationRate) / (1 - decelerationRate)
}

/**
 * Progressive resistance past a boundary.
 */
export function rubberband(overshoot, dimension, constant = 0.55) {
  if (dimension <= 0) return 0
  return (
    (overshoot * dimension * constant) /
    (dimension + constant * Math.abs(overshoot))
  )
}

/**
 * Interruptible spring solver using Apple's (dampingRatio, response) parameterization.
 * - dampingRatio = 1.0 -> critically damped (no overshoot)
 * - dampingRatio = 0.8 -> slight bounce for momentum-carrying gestures
 * - response = 0.32 -> snappiness in seconds
 */
export function createSpringController(initialValue = 0) {
  let current = initialValue
  let velocity = 0
  let rafId = null

  function stop() {
    if (rafId !== null) {
      cancelAnimationFrame(rafId)
      rafId = null
    }
  }

  function setImmediate(val) {
    stop()
    current = val
    velocity = 0
  }

  function animateTo(
    target,
    {
      initialVelocity = null,
      dampingRatio = 1.0,
      response = 0.32,
      onUpdate,
      onComplete
    } = {}
  ) {
    stop()

    if (prefersReducedMotion()) {
      current = target
      velocity = 0
      if (onUpdate) onUpdate(current)
      if (onComplete) onComplete()
      return
    }

    if (initialVelocity !== null) {
      velocity = initialVelocity
    }

    const stiffness = Math.pow((2 * Math.PI) / response, 2)
    const damping = (4 * Math.PI * dampingRatio) / response

    let lastTime = performance.now()

    function step(now) {
      const dt = Math.min((now - lastTime) / 1000, 0.032)
      lastTime = now

      const displacement = current - target
      const springForce = -stiffness * displacement
      const dampingForce = -damping * velocity
      const acceleration = springForce + dampingForce

      velocity += acceleration * dt
      current += velocity * dt

      if (onUpdate) {
        onUpdate(current)
      }

      if (Math.abs(current - target) < 0.4 && Math.abs(velocity) < 4) {
        current = target
        velocity = 0
        rafId = null
        if (onUpdate) onUpdate(current)
        if (onComplete) onComplete()
        return
      }

      rafId = requestAnimationFrame(step)
    }

    rafId = requestAnimationFrame(step)
  }

  return {
    get value() {
      return current
    },
    get velocity() {
      return velocity
    },
    setImmediate,
    animateTo,
    stop
  }
}
