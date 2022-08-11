with import <nixpkgs> {};

stdenv.mkDerivation {
    name = "git";
    buildInputs = [
        git
        git-lfs
    ];
}