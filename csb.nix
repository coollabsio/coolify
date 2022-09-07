with import <nixpkgs> {};

stdenv.mkDerivation {
    name = "environment";
    buildInputs = [
        git
        git-lfs
        docker-compose
    ];
}