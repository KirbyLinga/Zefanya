import java.util.Base64
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

val releaseKeys = Properties()
val releaseKeyFile = rootProject.file("key.properties")
if (releaseKeyFile.isFile) {
    releaseKeyFile.inputStream().use { releaseKeys.load(it) }
}
val hasReleaseKeys = listOf("storeFile", "storePassword", "keyAlias", "keyPassword")
    .all { !releaseKeys.getProperty(it).isNullOrBlank() }

android {
    namespace = "com.example.zefanya"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.example.zefanya"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseKeys) {
            create("release") {
                storeFile = rootProject.file(releaseKeys.getProperty("storeFile"))
                storePassword = releaseKeys.getProperty("storePassword")
                keyAlias = releaseKeys.getProperty("keyAlias")
                keyPassword = releaseKeys.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.findByName("release")
        }
    }
}

val releaseDefines = providers.gradleProperty("dart-defines")
tasks.matching { it.name == "preReleaseBuild" }.configureEach {
    doFirst {
        check(hasReleaseKeys && rootProject.file(releaseKeys.getProperty("storeFile")).isFile) {
            "Android release requires an approved keystore configured through android/key.properties."
        }
        val defines = releaseDefines.orNull.orEmpty().split(',')
            .filter { it.isNotBlank() }
            .map { String(Base64.getDecoder().decode(it), Charsets.UTF_8) }
            .associate { it.substringBefore('=') to it.substringAfter('=', "") }
        check(defines["USE_LIVE_API"] == "true" &&
            defines["zefanya.backend"] == "webzefanya" &&
            defines["zefanya.webzefanya.apiBaseUrl"] == "https://zefanya.shop/api" &&
            defines["zefanya.webzefanya.allowWrites"] != "true" &&
            defines["zefanya.devTools"] != "true") {
            "Android release requires the approved WebZefanya Live API profile."
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
