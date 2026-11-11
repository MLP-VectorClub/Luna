# Builds the two images (fpm, web) from the repository root:
#   docker buildx bake -f docker/docker-bake.hcl --set '*.platform=linux/arm64'
variable "REGISTRY" { default = "localhost:5000" }
variable "TAGS" { default = "dev" }
variable "GIT_COMMIT_HASH" { default = "unknown" }
variable "GIT_COMMIT_TIMESTAMP" { default = "" }

group "default" { targets = ["fpm", "web"] }

target "_common" {
  context    = "."
  dockerfile = "docker/Dockerfile"
  platforms  = ["linux/amd64", "linux/arm64"]
  args = {
    GIT_COMMIT_HASH      = GIT_COMMIT_HASH
    GIT_COMMIT_TIMESTAMP = GIT_COMMIT_TIMESTAMP
  }
}

target "fpm" {
  inherits = ["_common"]
  target   = "fpm"
  tags     = [for t in split(",", TAGS) : "${REGISTRY}/luna-fpm:${t}"]
}
target "web" {
  inherits = ["_common"]
  target   = "web"
  tags     = [for t in split(",", TAGS) : "${REGISTRY}/luna-web:${t}"]
}
