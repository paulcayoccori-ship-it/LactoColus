package pe.lactocolus.mobile

interface Platform {
    val name: String
}

expect fun getPlatform(): Platform